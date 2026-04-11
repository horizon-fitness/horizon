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

$app_id = (int) $_GET['id'];

// Fetch detailed application data
$stmt = $pdo->prepare("
    SELECT a.*, a.email as gym_email, a.contact_number as gym_contact,
           u.user_id as applicant_id, u.first_name, u.middle_name, u.last_name, u.email as owner_email, 
           u.contact_number as owner_contact, u.birth_date, u.sex,
           ad.address_line, ad.barangay, ad.city, ad.province, ad.region,
           r.first_name as reviewer_first, r.last_name as reviewer_last,
           g.gym_id 
    FROM gym_owner_applications a
    JOIN users u ON a.user_id = u.user_id
    JOIN addresses ad ON a.address_id = ad.address_id
    LEFT JOIN users r ON a.reviewed_by = r.user_id
    LEFT JOIN gyms g ON a.application_id = g.application_id
    WHERE a.application_id = ?
");
$stmt->execute([$app_id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$app) {
    die("Application not found.");
}

// Fetch Latest Web Subscription (Horizon)
$stmtSub = $pdo->prepare("
    SELECT cs.*, wp.plan_name, wp.price, wp.billing_cycle
    FROM client_subscriptions cs
    JOIN website_plans wp ON cs.website_plan_id = wp.website_plan_id
    WHERE cs.owner_user_id = ? 
    ORDER BY cs.created_at DESC LIMIT 1
");
$stmtSub->execute([$app['applicant_id']]);
$subscription = $stmtSub->fetch(PDO::FETCH_ASSOC);

// 4. Fetch Documents
$stmtDocs = $pdo->prepare("SELECT * FROM application_documents WHERE application_id = ?");
$stmtDocs->execute([$app_id]);
$documents = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

/**
 * HELPER: Parse custom formatted remarks into key-value pairs
 * This ensures data like Max Capacity and Bank Info (stored in remarks) is accessible.
 */
function parseRemarks($remarks)
{
    if (empty($remarks))
        return [];
    $data = [];

    $patterns = [
        'bank_name' => '/Bank:\s*([^||\n]+)/',
        'account_name' => '/Acct Name:\s*([^||\n]+)/',
        'account_number' => '/Acct No:\s*([^||\n]+)/',
        'platform_fee_preference' => '/Fee Pref:\s*([^||\n]+)/',
        'opening_time' => '/Opening:\s*([^||\n]+)/',
        'closing_time' => '/Closing:\s*([^||\n]+)/',
        'max_capacity' => '/Max Cap:\s*([^||\n]+)/',
        'has_lockers' => '/Lockers:\s*([^||\n]+)/',
        'has_shower' => '/Shower:\s*([^||\n]+)/',
        'has_parking' => '/Parking:\s*([^||\n]+)/',
        'has_wifi' => '/Wifi:\s*([^||\n]+)/',
        'about_text' => '/About:\s*(.*?)(?=\nRules:|$)/s',
        'rules_text' => '/Rules:\s*(.+)/s'
    ];

    foreach ($patterns as $key => $regex) {
        if (preg_match($regex, $remarks, $matches)) {
            $data[$key] = trim($matches[1]);
        }
    }

    // Normalize facility booleans 
    $yesNo = ['has_lockers', 'has_shower', 'has_parking', 'has_wifi'];
    foreach ($yesNo as $key) {
        if (isset($data[$key])) {
            $data[$key] = (strtolower($data[$key]) === 'yes');
        } else {
            $data[$key] = false;
        }
    }

    return $data;
}

// Hydrate $app with parsed metadata
$app = array_merge($app, parseRemarks($app['remarks']));

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

function formatLabel($key, $map)
{
    return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
}

$is_ajax = isset($_GET['ajax']);
$page_title = "Application Details: " . $app['gym_name'];
$active_page = "tenants";

if ($is_ajax): ?>
    <!-- AJAX Modal Content Only -->
    <div class="space-y-8 max-h-[80vh] overflow-y-auto px-1 pr-3 no-scrollbar max-w-full overflow-x-hidden">
        <header class="flex items-center justify-between mb-10 border-b border-white/5 pb-8 relative z-10">
            <div class="flex items-center gap-6">
                <div
                    class="size-16 rounded-[24px] bg-primary/10 border border-primary/20 flex items-center justify-center overflow-hidden shadow-xl shadow-primary/5">
                    <?php
                    $logo = array_filter($documents, fn($d) => strtolower($d['document_type']) === 'gym logo');
                    $logo = reset($logo);
                    if ($logo):
                        $logoPath = $logo['file_path'];
                        if (!str_starts_with($logoPath, 'data:')) {
                            if (str_starts_with($logoPath, 'uploads/')) {
                                $logoPath = '../' . $logoPath;
                            } elseif (!str_starts_with($logoPath, '../') && !str_starts_with($logoPath, 'http')) {
                                $logoPath = '../uploads/applications/' . $logoPath;
                            }
                        }
                        ?>
                        <img src="<?= htmlspecialchars($logoPath) ?>" class="size-full object-contain">
                    <?php else: ?>
                        <span
                            class="text-primary font-black text-2xl italic"><?= strtoupper(substr($app['gym_name'], 0, 2)) ?></span>
                    <?php endif; ?>
                </div>
                <div>
                    <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none mb-2">
                        <?= htmlspecialchars($app['gym_name']) ?></h2>
                    <div class="flex items-center gap-3">
                        <?php if ($app['application_status'] === 'Pending'): ?>
                            <span
                                class="px-2 py-0.5 rounded-full bg-amber-500/10 border border-amber-500/20 text-[9px] text-amber-500 font-bold uppercase tracking-wider">Awaiting
                                Review</span>
                        <?php elseif ($app['application_status'] === 'Approved'): ?>
                            <span
                                class="px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[9px] text-emerald-500 font-bold uppercase tracking-wider italic">Verified
                                Partner</span>
                        <?php else: ?>
                            <span
                                class="px-2 py-0.5 rounded-full bg-red-500/10 border border-red-500/20 text-[9px] text-red-500 font-bold uppercase tracking-wider">Rejected</span>
                        <?php endif; ?>
                        <span class="text-[9px] text-gray-500 font-bold uppercase tracking-wider italic">Submitted:
                            <?= date('M d, Y', strtotime($app['submitted_at'])) ?></span>
                    </div>
                </div>
            </div>
            <button onclick="closeApplicationModal()"
                class="size-8 rounded-full bg-white/5 hover:bg-white/10 flex items-center justify-center text-gray-400 group transition-all">
                <span class="material-symbols-outlined text-xl group-hover:rotate-90 transition-transform">close</span>
            </button>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-8">
            <!-- Owner Column -->
            <div class="space-y-6">
                <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.2em] border-l-2 border-primary pl-3">
                    Owner Contact</h4>
                <div class="grid grid-cols-1 gap-4">
                    <div class="bg-white/[0.03] p-5 rounded-2xl border border-white/5 shadow-sm">
                        <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Owner Name</p>
                        <p class="text-sm font-bold text-white tracking-tight">
                            <?= htmlspecialchars($app['first_name'] . ' ' . $app['middle_name'] . ' ' . $app['last_name']) ?>
                        </p>
                    </div>
                    <div class="bg-white/[0.03] p-5 rounded-2xl border border-white/5 shadow-sm">
                        <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Email / Contact</p>
                        <p class="text-sm font-bold text-white tracking-tight"><?= htmlspecialchars($app['owner_email']) ?>
                        </p>
                        <p class="text-[11px] text-gray-400 mt-1.5 font-medium tracking-tight">
                            <?= htmlspecialchars($app['owner_contact']) ?></p>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-white/[0.03] p-5 rounded-2xl border border-white/5 shadow-sm">
                            <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Birth Date</p>
                            <p class="text-sm font-bold text-white tracking-tight italic">
                                <?= $app['birth_date'] ? date('M d, Y', strtotime($app['birth_date'])) : '---' ?></p>
                        </div>
                        <div class="bg-white/[0.03] p-5 rounded-2xl border border-white/5 shadow-sm">
                            <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Sex</p>
                            <div class="flex items-center gap-2">
                                <span
                                    class="material-symbols-outlined text-[14px] text-primary"><?= strtolower($app['sex'] ?? '') === 'male' ? 'male' : 'female' ?></span>
                                <p class="text-sm font-bold text-white tracking-tight uppercase italic">
                                    <?= htmlspecialchars($app['sex'] ?: '---') ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white/[0.03] p-5 rounded-2xl border border-white/5 shadow-sm">
                        <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Identity
                            Verification</p>
                        <div class="flex items-center gap-2.5">
                            <span class="material-symbols-outlined text-primary text-sm">badge</span>
                            <p class="text-sm font-bold text-white tracking-tight">
                                <?= formatLabel($app['owner_valid_id_type'], $friendlyNames) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Business Column -->
            <div class="space-y-6">
                <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.2em] border-l-2 border-primary pl-3">
                    Business Profile</h4>
                <div class="grid grid-cols-1 gap-4">
                    <div class="bg-white/[0.03] p-5 rounded-2xl border border-white/5 shadow-sm">
                        <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Legal Entity / Type
                        </p>
                        <p class="text-sm font-bold text-white tracking-tight italic">
                            <?= htmlspecialchars($app['business_name']) ?></p>
                        <p class="text-[11px] text-primary mt-1.5 font-black uppercase tracking-wider italic">
                            <?= formatLabel($app['business_type'], $friendlyNames) ?></p>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-white/[0.03] p-5 rounded-2xl border border-white/5 shadow-sm">
                            <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">TIN</p>
                            <p class="text-sm font-bold text-white"><?= htmlspecialchars($app['bir_number']) ?></p>
                        </div>
                        <div class="bg-white/[0.03] p-5 rounded-2xl border border-white/5 shadow-sm">
                            <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Business Permit
                                Number</p>
                            <p class="text-sm font-bold text-white"><?= htmlspecialchars($app['business_permit_no']) ?></p>
                        </div>
                    </div>
                    <div class="bg-white/[0.03] p-5 rounded-2xl border border-white/5 shadow-sm">
                        <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Facility Address</p>
                        <p class="text-sm font-bold text-white leading-relaxed tracking-tight">
                            <?= htmlspecialchars($app['address_line']) ?><br>
                            <span class="text-[11px] text-gray-400 font-medium italic mt-1">
                                <?= htmlspecialchars($app['barangay'] . ', ' . $app['city'] . ', ' . $app['province'] . ', ' . $app['region']) ?>
                            </span>
                        </p>
                    </div>
                    <div class="grid grid-cols-1 gap-4">
                        <div class="bg-white/[0.03] p-5 rounded-2xl border border-white/5 shadow-sm">
                            <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Official Gym
                                Email</p>
                            <p class="text-sm font-bold text-white tracking-tight italic">
                                <?= htmlspecialchars($app['gym_email']) ?></p>
                        </div>
                        <div class="bg-white/[0.03] p-5 rounded-2xl border border-white/5 shadow-sm">
                            <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Official Gym
                                Contact</p>
                            <p class="text-sm font-bold text-white tracking-tight italic">
                                <?= htmlspecialchars($app['gym_contact']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
            <div
                class="bg-primary/5 border border-primary/20 backdrop-blur-xl rounded-[32px] p-8 shadow-2xl shadow-primary/10 flex-1">
                <div class="flex items-center gap-4 mb-8">
                    <div
                        class="size-10 rounded-2xl bg-primary flex items-center justify-center shadow-lg shadow-primary/20">
                        <span class="material-symbols-outlined text-white text-xl">workspace_premium</span>
                    </div>
                    <div>
                        <h4 class="text-[11px] font-black uppercase text-primary tracking-[0.2em] italic">Horizon Web
                            Subscription</h4>
                        <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest mt-1">Platform Plan Details
                        </p>
                    </div>
                    <?php if ($subscription): ?>
                        <span
                            class="ml-auto px-4 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[10px] text-emerald-500 font-black uppercase italic tracking-widest shadow-inner"><?= htmlspecialchars($subscription['subscription_status']) ?></span>
                    <?php else: ?>
                        <span
                            class="ml-auto px-4 py-1.5 rounded-full bg-red-500/10 border border-red-500/20 text-[10px] text-red-500 font-black uppercase italic tracking-widest">Awaiting
                            Plan</span>
                    <?php endif; ?>
                </div>

                <?php if ($subscription): ?>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-white/5 p-4 rounded-xl border border-white/5">
                            <p class="text-[8px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Selected Plan</p>
                            <p class="text-[11px] font-extrabold text-white tracking-tight uppercase italic text-primary">
                                <?= htmlspecialchars($subscription['plan_name']) ?></p>
                        </div>
                        <div class="bg-white/5 p-4 rounded-xl border border-white/5">
                            <p class="text-[8px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Plan Price</p>
                            <p class="text-[11px] font-extrabold text-white tracking-tight italic">
                                ₱<?= number_format($subscription['price'], 2) ?></p>
                        </div>
                        <div class="bg-white/5 p-4 rounded-xl border border-white/5">
                            <p class="text-[8px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Billing Cycle</p>
                            <p class="text-[11px] font-extrabold text-white tracking-tight uppercase italic">
                                <?= htmlspecialchars($subscription['billing_cycle']) ?></p>
                        </div>
                        <div class="bg-white/5 p-4 rounded-xl border border-white/5">
                            <p class="text-[8px] font-black uppercase text-gray-500 tracking-widest mb-1.5">Payment</p>
                            <p
                                class="text-[11px] font-extrabold <?= strtolower($subscription['payment_status']) === 'paid' ? 'text-emerald-500' : 'text-amber-500' ?> tracking-tight uppercase italic">
                                <?= htmlspecialchars($subscription['payment_status']) ?></p>
                        </div>
                    </div>
                <?php else: ?>
                    <div
                        class="bg-white/[0.02] p-6 rounded-2xl border border-white/5 border-dashed flex flex-col items-center justify-center text-center">
                        <span class="material-symbols-outlined text-3xl text-gray-700 mb-2">payments</span>
                        <p class="text-[9px] font-bold text-gray-500 uppercase tracking-widest italic">Awaiting subscription
                            choice upon approval</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bg-white/[0.03] border border-white/10 backdrop-blur-md rounded-[32px] p-8 shadow-xl flex-1">
                <div class="flex items-center gap-4 mb-8">
                    <div
                        class="size-10 rounded-2xl bg-white/5 flex items-center justify-center shadow-lg border border-white/5">
                        <span class="material-symbols-outlined text-gray-400 text-xl">schedule</span>
                    </div>
                    <div>
                        <h4 class="text-[11px] font-black uppercase text-gray-400 tracking-[0.2em] italic">Operating Hours
                        </h4>
                        <p class="text-[9px] text-gray-500 font-bold uppercase tracking-widest mt-1">Gym Open/Close Timing
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 h-[104px]">
                    <div class="bg-white/5 p-6 rounded-xl border border-white/5 flex flex-col justify-center text-center">
                        <p class="text-[8px] font-black uppercase text-gray-500 tracking-widest mb-1">Opening Time</p>
                        <p class="text-lg font-black text-white italic tracking-tighter uppercase whitespace-nowrap">
                            <?= htmlspecialchars($app['opening_time'] ?: '---') ?></p>
                    </div>
                    <div class="bg-white/5 p-6 rounded-xl border border-white/5 flex flex-col justify-center text-center">
                        <p class="text-[8px] font-black uppercase text-gray-500 tracking-widest mb-1">Closing Time</p>
                        <p class="text-lg font-black text-white italic tracking-tighter uppercase whitespace-nowrap">
                            <?= htmlspecialchars($app['closing_time'] ?: '---') ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">

            <div class="bg-primary/5 border border-primary/20 backdrop-blur-md rounded-2xl p-7 shadow-lg shadow-primary/5">
                <h4
                    class="text-[9px] font-black uppercase text-primary tracking-[0.2em] mb-5 border-b border-primary/10 pb-3">
                    Payout Information</h4>
                <div class="space-y-4">
                    <div class="flex justify-between items-center bg-white/[0.02] p-3 rounded-xl border border-white/5">
                        <span class="text-[10px] text-gray-400 uppercase font-black tracking-widest">Bank Name</span>
                        <span
                            class="text-xs font-black text-white italic uppercase"><?= htmlspecialchars($app['bank_name'] ?: 'Not Provided') ?></span>
                    </div>
                    <div class="flex justify-between items-center bg-white/[0.02] p-3 rounded-xl border border-white/5">
                        <span class="text-[10px] text-gray-400 uppercase font-black tracking-widest">Account Name</span>
                        <span
                            class="text-xs font-black text-white uppercase italic"><?= htmlspecialchars($app['account_name'] ?: '---') ?></span>
                    </div>
                    <div class="flex justify-between items-center bg-white/[0.02] p-3 rounded-xl border border-white/5">
                        <span class="text-[10px] text-gray-400 uppercase font-black tracking-widest">Account Number</span>
                        <span
                            class="text-xs font-black text-white"><?= htmlspecialchars($app['account_number'] ?: '---') ?></span>
                    </div>

                </div>
            </div>

            <div class="bg-white/[0.03] border border-white/10 backdrop-blur-md rounded-2xl p-7 shadow-xl">
                <div class="flex justify-between items-center border-b border-white/5 pb-3 mb-5">
                    <h4 class="text-[9px] font-black uppercase text-gray-400 tracking-[0.2em]">Facility Information</h4>
                </div>
                <div class="flex items-end gap-3 mb-6">
                    <p class="text-4xl font-black italic text-white leading-none tracking-tighter">
                        <?= htmlspecialchars($app['max_capacity'] ?: '0') ?></p>
                    <p class="text-[10px] font-black uppercase text-gray-500 pb-1 italic tracking-widest leading-none">Max
                        Capacity</p>
                </div>
                <div class="flex flex-wrap gap-2 pt-2 border-t border-white/5">
                    <span
                        class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/5 text-[9px] font-black uppercase tracking-widest <?= $app['has_shower'] ? 'text-primary' : 'text-gray-600 line-through' ?>">Shower</span>
                    <span
                        class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/5 text-[9px] font-black uppercase tracking-widest <?= $app['has_parking'] ? 'text-primary' : 'text-gray-600 line-through' ?>">Parking</span>
                    <span
                        class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/5 text-[9px] font-black uppercase tracking-widest <?= $app['has_wifi'] ? 'text-primary' : 'text-gray-600 line-through' ?>">Wi-Fi</span>
                    <span
                        class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/5 text-[9px] font-black uppercase tracking-widest <?= $app['has_lockers'] ? 'text-primary' : 'text-gray-600 line-through' ?>">Lockers</span>
                </div>
            </div>
        </div>

        <!-- About & Rules -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div class="bg-white/[0.03] border border-white/10 rounded-2xl p-7 shadow-xl">
                <h4
                    class="text-[9px] font-black uppercase text-gray-400 tracking-[0.2em] mb-4 border-b border-white/5 pb-3">
                    About the Gym</h4>
                <p class="text-xs text-gray-400 leading-relaxed italic">
                    <?= nl2br(htmlspecialchars($app['about_text'] ?: 'No description provided.')) ?></p>
            </div>
            <div class="bg-white/[0.03] border border-white/10 rounded-2xl p-7 shadow-xl">
                <h4
                    class="text-[9px] font-black uppercase text-gray-400 tracking-[0.2em] mb-4 border-b border-white/5 pb-3">
                    Gym House Rules</h4>
                <p class="text-xs text-gray-400 leading-relaxed italic">
                    <?= nl2br(htmlspecialchars($app['rules_text'] ?: 'No rules provided.')) ?></p>
            </div>
        </div>

        <!-- Formal Document Viewer -->
        <div>
            <h4 class="text-[10px] font-black uppercase text-primary tracking-[0.2em] border-l-2 border-primary pl-3 mb-6">
                Credential Verification</h4>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <?php foreach ($documents as $doc):
                    $docPath = $doc['file_path'];
                    if (!str_starts_with($docPath, 'data:')) {
                        if (str_starts_with($docPath, 'uploads/')) {
                            $docPath = '../' . $docPath;
                        } elseif (!str_starts_with($docPath, '../') && !str_starts_with($docPath, 'http')) {
                            $docPath = '../uploads/applications/' . $docPath;
                        }
                    }
                    $isPdf = (strtolower(pathinfo($docPath, PATHINFO_EXTENSION)) === 'pdf') || str_starts_with(strtolower($docPath), 'data:application/pdf');
                    ?>
                    <div class="flex flex-col gap-3">
                        <div class="group relative bg-white/[0.02] border border-white/10 rounded-2xl overflow-hidden aspect-[4/3] cursor-zoom-in modal-img-preview shadow-lg hover:border-primary/50 transition-all"
                            data-src="<?= htmlspecialchars($docPath) ?>"
                            data-title="<?= htmlspecialchars($doc['document_type']) ?>">
                            <div class="absolute inset-0 p-2">
                                <div class="w-full h-full rounded-xl overflow-hidden bg-background-dark/50 <?= $isPdf ? 'relative' : '' ?>">
                                    <?php if ($isPdf): ?>
                                        <iframe src="<?= htmlspecialchars($docPath) ?>#toolbar=0&navpanes=0&scrollbar=0" class="w-full h-full object-cover pointer-events-none opacity-60 group-hover:opacity-100 transition-opacity" style="border:none; overflow:hidden;" scrolling="no"></iframe>
                                        <div class="absolute inset-0 bg-transparent z-10"></div>
                                    <?php else: ?>
                                        <img src="<?= htmlspecialchars($docPath) ?>"
                                            class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 opacity-60 group-hover:opacity-100">
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div
                                class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity bg-black/20 backdrop-blur-sm z-20">
                                <span class="material-symbols-outlined text-primary text-3xl">fullscreen</span>
                            </div>
                        </div>
                        <div class="px-2">
                            <p class="text-[10px] font-black uppercase text-white tracking-[0.1em]">
                                <?= htmlspecialchars($doc['document_type']) ?></p>
                            <p class="text-[8px] font-bold text-gray-500 uppercase tracking-widest mt-0.5">Certified Document
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($app['application_status'] === 'Pending'): ?>
            <div class="flex gap-3 pt-6 border-t border-white/5">
                <form method="POST" action="action/process_application.php" class="flex-1">
                    <input type="hidden" name="application_id" value="<?= $app_id ?>">
                    <input type="hidden" name="action" value="">
                    <button type="button"
                        onclick="confirmAction(this.form, 'approve', 'Approve Application', 'Are you sure you want to approve this gym? They will be granted system access immediately.')"
                        class="w-full py-3 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white text-[10px] font-black uppercase tracking-widest shadow-lg shadow-emerald-500/20 transition-all">
                        Approve Now
                    </button>
                </form>
                <form method="POST" action="action/process_application.php" class="flex-1">
                    <input type="hidden" name="application_id" value="<?= $app_id ?>">
                    <input type="hidden" name="action" value="">
                    <button type="button"
                        onclick="confirmAction(this.form, 'reject', 'Reject Application', 'Are you sure you want to reject this application? This will archive the request in the Rejected History tab.')"
                        class="w-full py-3 rounded-xl bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 text-red-500 text-[10px] font-black uppercase tracking-widest transition-all">
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
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />
    <title><?= $page_title ?> | Herdoza Fitness</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet" />
    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200"
        rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)" } } }
        }
    </script>
    <style>
        body {
            font-family: 'Lexend', sans-serif;
            background-color: #0a090d;
            color: white;
        }

        .glass-card {
            background: #14121a;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 24px;
        }

        /* Sidebar Hover Logic - ADJUSTED WIDTHS */
        .sidebar-nav {
            width: 110px;
            /* Increased slightly from 100px */
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }

        .sidebar-nav:hover {
            width: 300px;
            /* Increased from 280px for better text fit */
        }

        .nav-text {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
        }

        .sidebar-nav:hover .nav-text {
            opacity: 1;
            transform: translateX(0);
            pointer-events: auto;
        }

        .nav-section-header {
            max-height: 0;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin-top: 0 !important;
            margin-bottom: 0 !important;
        }

        .sidebar-nav:hover .nav-section-header {
            max-height: 25px;
        }

        .sidebar-nav:hover .nav-section-header.mt-4 {
            margin-top: 1rem !important;
        }

        .sidebar-nav:hover .nav-section-header.mt-6 {
            margin-top: 1.5rem !important;
        }

        .sidebar-nav:hover .nav-section-header.mb-2 {
            margin-bottom: 0.5rem !important;
        }

        .sidebar-content {
            gap: 0.5rem;
            transition: all 0.3s ease-in-out;
        }

        .sidebar-nav:hover .sidebar-content {
            gap: 1rem;
        }

        /* End Sidebar Hover Logic */

        .nav-link {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.05em;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .active-nav {
            color: #8c2bee !important;
            position: relative;
        }

        .active-nav::after {
            content: '';
            position: absolute;
            right: 0px;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 20px;
            background: #8c2bee;
            border-radius: 99px;
        }

        @media (max-width: 1023px) {
            .active-nav::after {
                display: none;
            }
        }

        .alert-pulse {
            animation: alert-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        @keyframes alert-pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: .5;
            }
        }

        .status-card-green {
            border: 1px solid #10b981;
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(20, 18, 26, 1) 100%);
        }

        .status-card-yellow {
            border: 1px solid #f59e0b;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.05) 0%, rgba(20, 18, 26, 1) 100%);
        }

        .status-card-red {
            border: 1px solid #ef4444;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.05) 0%, rgba(20, 18, 26, 1) 100%);
        }

        .dashed-container {
            border: 2px dashed rgba(255, 255, 255, 0.1);
            border-radius: 24px;
        }

        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #0a090d;
        }

        ::-webkit-scrollbar-thumb {
            background: #14121a;
            border-radius: 10px;
        }
        /* Sidebar-Aware Modal Logic */
        #confirmModal {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 110px;
            z-index: 350;
            /* Higher than navigation */
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .flex-important {
            display: flex !important;
        }

        .sidebar-nav:hover~#confirmModal {
            left: 300px;
        }

        @media (max-width: 1023px) {
            #confirmModal {
                left: 0 !important;
            }
        }
    </style>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
            }
        }
        setInterval(updateHeaderClock, 1000);
        window.addEventListener('DOMContentLoaded', updateHeaderClock);
    </script>
</head>

<body class="antialiased flex flex-row min-h-screen">

    <nav
        class="sidebar-nav flex flex-col bg-[#0a090d] border-r border-white/5 sticky top-0 h-screen px-7 py-8 z-50 shrink-0">
        <div class="mb-8">
            <div class="flex items-center gap-4 mb-6">
                <div class="size-10 rounded-xl bg-[#7f13ec] flex items-center justify-center shadow-lg shrink-0">
                    <span class="material-symbols-outlined text-white text-2xl">bolt</span>
                </div>
                <h1 class="nav-text text-xl font-black italic uppercase tracking-tighter text-white">Horizon System</h1>
            </div>
        </div>

        <div class="sidebar-content flex-1 overflow-y-auto no-scrollbar pr-2 pb-10 flex flex-col">
            <!-- Overview Section -->
            <div class="nav-section-header px-0 mb-2 mt-4">
                <span class="nav-text text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Overview</span>
            </div>
            <a href="superadmin_dashboard.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'dashboard') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
                <span class="nav-text">Dashboard</span>
            </a>

            <!-- Management Section -->
            <div class="nav-section-header px-0 mb-2 mt-6">
                <span class="nav-text text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Management</span>
            </div>
            <a href="tenant_management.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'tenants') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">business</span>
                <span class="nav-text">Tenant Management</span>
            </a>

            <a href="subscription_logs.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'subscriptions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">history_edu</span>
                <span class="nav-text">Subscription Logs</span>
            </a>

            <a href="rbac_management.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'rbac') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">security</span>
                <span class="nav-text">Access Control</span>
            </a>

            <a href="real_time_occupancy.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'occupancy') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">group</span>
                <span class="nav-text">Real-Time Occupancy</span>
            </a>

            <a href="recent_transaction.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'transactions') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span>
                <span class="nav-text">Recent Transactions</span>
            </a>

            <!-- System Section -->
            <div class="nav-section-header px-0 mb-2 mt-6">
                <span class="nav-text text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">System</span>
            </div>
            <a href="system_alerts.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'alerts') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">notifications_active</span>
                <span class="nav-text">System Alerts</span>
            </a>

            <a href="system_reports.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'reports') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">analytics</span>
                <span class="nav-text">Reports</span>
            </a>

            <a href="sales_report.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'sales_report') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">monitoring</span>
                <span class="nav-text">Sales Reports</span>
            </a>

            <a href="audit_logs.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'audit_logs') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">assignment</span>
                <span class="nav-text">Audit Logs</span>
            </a>

            <a href="backup.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'backup') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">backup</span>
                <span class="nav-text">Backup</span>
            </a>
        </div>

        <div class="mt-auto pt-10 border-t border-white/10 flex flex-col gap-4">
            <div class="nav-section-header px-0 mb-2">
                <span class="nav-text text-[10px] font-black uppercase tracking-[0.2em] text-gray-400">Account</span>
            </div>
            <a href="settings.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'settings') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">settings</span>
                <span class="nav-text">Settings</span>
            </a>
            <a href="profile.php"
                class="nav-link flex items-center gap-4 py-2 <?= ($active_page == 'profile') ? 'active-nav text-primary' : 'text-gray-400 hover:text-white' ?>">
                <span class="material-symbols-outlined text-xl shrink-0">person</span>
                <span class="nav-text">Profile</span>
            </a>
            <a href="../logout.php"
                class="text-gray-400 hover:text-rose-500 transition-colors flex items-center gap-4 group">
                <span
                    class="material-symbols-outlined group-hover:translate-x-1 transition-transform text-xl shrink-0">logout</span>
                <span class="nav-link nav-text">Sign Out</span>
            </a>
        </div>
    </nav>

    <div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
        <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
            <a href="javascript:history.back()"
                class="inline-flex items-center gap-2 text-gray-500 hover:text-white transition-colors mb-8 group">
                <span
                    class="material-symbols-outlined transition-transform group-hover:-translate-x-1">arrow_back</span>
                <span class="text-xs font-black uppercase tracking-widest">Back to Management</span>
            </a>

            <header class="mb-10 flex flex-row justify-between items-end gap-6">
                <div class="flex items-center gap-6">
                    <?php if ($logoPath): ?>
                        <img src="<?= htmlspecialchars($logoPath) ?>"
                            class="size-24 rounded-[32px] object-cover border-2 border-primary/20 shadow-2xl shadow-primary/10">
                    <?php else: ?>
                        <div
                            class="size-24 rounded-[32px] bg-primary/10 border-2 border-primary/20 flex items-center justify-center text-primary font-black italic text-4xl uppercase">
                            <?= substr($app['gym_name'], 0, 1) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <div class="flex items-center gap-3 mb-2">
                            <span
                                class="px-3 py-1 rounded-full bg-primary/10 border border-primary/20 text-[10px] text-primary font-black uppercase italic tracking-widest">Application
                                Details</span>
                            <?php if ($app['application_status'] === 'Pending'): ?>
                                <span
                                    class="px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/20 text-[10px] text-amber-500 font-black uppercase italic tracking-widest">Status:
                                    Pending</span>
                            <?php elseif ($app['application_status'] === 'Approved'): ?>
                                <span
                                    class="px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[10px] text-emerald-500 font-black uppercase italic tracking-widest">Status:
                                    Approved</span>
                            <?php else: ?>
                                <span
                                    class="px-3 py-1 rounded-full bg-red-500/10 border border-red-500/20 text-[10px] text-red-500 font-black uppercase italic tracking-widest">Status:
                                    Rejected</span>
                            <?php endif; ?>
                        </div>
                        <h2 class="text-4xl font-black italic uppercase tracking-tighter text-white leading-none">
                            <?= htmlspecialchars($app['gym_name']) ?></h2>
                        <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-1">Submitted on
                            <?= date('M d, Y h:i A', strtotime($app['submitted_at'])) ?></p>
                    </div>
                </div>
                <div class="text-right">
                    <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">
                        00:00:00 AM</p>
                    <p class="text-primary text-[9px] font-black uppercase tracking-[0.2em] opacity-80">
                        <?= date('l, M d, Y') ?></p>
                </div>
            </header>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-10">
                <!-- Owner Information -->
                <div class="glass-card p-8">
                    <div class="grid grid-cols-1 gap-6">
                        <div>
                            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1.5 italic">Full
                                Name</p>
                            <p class="text-sm font-bold text-white">
                                <?= htmlspecialchars($app['first_name'] . ' ' . $app['middle_name'] . ' ' . $app['last_name']) ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1.5 italic">
                                Email & Contact</p>
                            <p class="text-sm font-bold text-white"><?= htmlspecialchars($app['owner_email']) ?></p>
                            <p class="text-[11px] text-primary font-black uppercase italic mt-1">
                                <?= htmlspecialchars($app['owner_contact']) ?></p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1.5 italic">
                                    Birth Date</p>
                                <p class="text-sm font-bold text-white italic tracking-tight">
                                    <?= $app['birth_date'] ? date('M d, Y', strtotime($app['birth_date'])) : '---' ?>
                                </p>
                            </div>
                            <div>
                                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1.5 italic">
                                    Sex / Gender</p>
                                <div class="flex items-center gap-2">
                                    <span
                                        class="material-symbols-outlined text-primary text-sm tracking-tight"><?= strtolower($app['sex'] ?? '') === 'male' ? 'male' : 'female' ?></span>
                                    <p class="text-sm font-bold text-white italic uppercase tracking-tighter">
                                        <?= htmlspecialchars($app['sex'] ?: '---') ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Business Information -->
                <div class="glass-card p-8">
                    <h3
                        class="text-sm font-black italic uppercase tracking-widest mb-6 text-primary border-b border-white/5 pb-4">
                        Business Details</h3>
                    <div class="space-y-4">
                        <div>
                            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Registered
                                Entity Name</p>
                            <p class="text-sm font-bold tracking-tight text-white">
                                <?= htmlspecialchars($app['business_name']) ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Business Type
                            </p>
                            <p class="text-sm font-bold tracking-tight text-white">
                                <?= formatLabel($app['business_type'], $friendlyNames) ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Gym Official
                                Contact</p>
                            <p class="text-sm font-bold tracking-tight text-white">
                                <?= htmlspecialchars($app['email']) ?></p>
                            <p class="text-[11px] text-gray-400 font-medium italic mt-1">
                                <?= htmlspecialchars($app['contact_number']) ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Facility
                                Address</p>
                            <p class="text-sm font-bold leading-relaxed tracking-tight text-white">
                                <?= htmlspecialchars($app['address_line'] . ', ' . $app['barangay'] . ', ' . $app['city'] . ', ' . $app['province'] . ', ' . $app['region']) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Facility & Payout Profile -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-10">
                <div class="glass-card p-8 border-primary/20 bg-primary/5">
                    <h3
                        class="text-sm font-black italic uppercase tracking-widest mb-6 text-primary border-b border-primary/10 pb-4">
                        Financial Payouts</h3>
                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Bank Partner
                            </p>
                            <p class="text-sm font-black italic uppercase text-white">
                                <?= htmlspecialchars($app['bank_name'] ?: 'Not Provided') ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Account No.
                            </p>
                            <p class="text-sm font-bold text-white tracking-widest">
                                <?= htmlspecialchars($app['account_number'] ?: '---') ?></p>
                        </div>
                        <div class="col-span-2">
                            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Account
                                Holder</p>
                            <p class="text-sm font-bold text-white uppercase italic tracking-tight">
                                <?= htmlspecialchars($app['account_name'] ?: '---') ?></p>
                        </div>
                        <div class="col-span-2 pt-2 border-t border-white/5">
                            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Settlement
                                Preference</p>
                            <p class="text-[10px] font-black uppercase text-primary italic tracking-tight">
                                <?= formatLabel($app['platform_fee_preference'], $friendlyNames) ?></p>
                        </div>
                    </div>
                </div>
                <div class="glass-card p-8 bg-white/5">
                    <h3
                        class="text-sm font-black italic uppercase tracking-widest mb-6 text-white border-b border-white/10 pb-4">
                        Facility Status</h3>
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <p class="text-4xl font-black italic text-white tracking-tighter">
                                <?= htmlspecialchars($app['max_capacity'] ?: '0') ?></p>
                            <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest">Max Capacity</p>
                        </div>
                        <div class="text-right">
                            <p class="text-[9px] font-black uppercase text-gray-500 tracking-widest mb-1">Operating
                                Hours</p>
                            <p class="text-xs font-bold text-white"><?= $app['opening_time'] ?> -
                                <?= $app['closing_time'] ?></p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span
                            class="px-2.5 py-1 rounded bg-white/5 border border-white/10 text-[8px] font-black uppercase tracking-widest <?= $app['has_shower'] ? 'text-primary' : 'text-gray-600 line-through' ?>">Shower</span>
                        <span
                            class="px-2.5 py-1 rounded bg-white/5 border border-white/10 text-[8px] font-black uppercase tracking-widest <?= $app['has_parking'] ? 'text-primary' : 'text-gray-600 line-through' ?>">Parking</span>
                        <span
                            class="px-2.5 py-1 rounded bg-white/5 border border-white/10 text-[8px] font-black uppercase tracking-widest <?= $app['has_wifi'] ? 'text-primary' : 'text-gray-600 line-through' ?>">Wi-Fi</span>
                        <span
                            class="px-2.5 py-1 rounded bg-white/5 border border-white/10 text-[8px] font-black uppercase tracking-widest <?= $app['has_lockers'] ? 'text-primary' : 'text-gray-600 line-through' ?>">Lockers</span>
                    </div>
                </div>
            </div>
    </div>

    <!-- About & Rules (Full Page) -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-10">
        <div class="glass-card p-8 bg-white/[0.02]">
            <h3
                class="text-sm font-black italic uppercase tracking-widest mb-6 text-primary border-b border-white/5 pb-4">
                About the Gym</h3>
            <p class="text-xs text-gray-400 leading-relaxed italic">
                <?= nl2br(htmlspecialchars($app['about_text'] ?: 'No description provided.')) ?></p>
        </div>
        <div class="glass-card p-8 bg-white/[0.02]">
            <h3
                class="text-sm font-black italic uppercase tracking-widest mb-6 text-primary border-b border-white/5 pb-4">
                Gym House Rules</h3>
            <p class="text-xs text-gray-400 leading-relaxed italic">
                <?= nl2br(htmlspecialchars($app['rules_text'] ?: 'No rules provided.')) ?></p>
        </div>
    </div>

    <!-- Horizon Web Subscription -->
    <div
        class="bg-primary/5 border border-primary/20 backdrop-blur-2xl rounded-[40px] p-10 mb-10 shadow-3xl shadow-primary/10 relative overflow-hidden group">
        <div
            class="absolute -right-20 -top-20 size-64 bg-primary/10 blur-[100px] group-hover:bg-primary/20 transition-all rounded-full duration-1000">
        </div>

        <div class="flex flex-col md:flex-row md:items-center justify-between gap-8 relative z-10">
            <div class="flex items-center gap-6">
                <div
                    class="size-16 rounded-[24px] bg-primary flex items-center justify-center shadow-2xl shadow-primary/30">
                    <span class="material-symbols-outlined text-white text-3xl">workspace_premium</span>
                </div>
                <div>
                    <h3 class="text-2xl font-black italic uppercase italic tracking-tighter text-white">Platform Plan
                    </h3>
                    <p class="text-xs font-bold text-primary uppercase tracking-[0.2em] mt-1">Horizon Management Suite
                    </p>
                </div>
            </div>

            <div class="flex flex-col md:items-end gap-3">
                <?php if ($subscription): ?>
                    <div class="flex items-center gap-4">
                        <span
                            class="px-5 py-2 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-xs text-emerald-500 font-extrabold uppercase italic tracking-widest shadow-inner">Status:
                            <?= htmlspecialchars($subscription['subscription_status']) ?></span>
                        <span
                            class="px-5 py-2 rounded-2xl bg-white/5 border border-white/10 text-xs text-white font-extrabold uppercase italic tracking-widest italic"><?= htmlspecialchars($subscription['payment_status']) ?></span>
                    </div>
                <?php else: ?>
                    <span
                        class="px-5 py-2 rounded-2xl bg-red-500/10 border border-red-500/20 text-xs text-red-500 font-extrabold uppercase italic tracking-widest italic">Awaiting
                        Plan Selection</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($subscription): ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mt-12 relative z-10">
                <div class="bg-white/[0.03] p-8 rounded-[32px] border border-white/10 backdrop-blur-md">
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-2 italic">Applied Package
                    </p>
                    <p class="text-xl font-black text-white italic uppercase italic">
                        <?= htmlspecialchars($subscription['plan_name']) ?></p>
                    <p class="text-[10px] text-primary font-black uppercase mt-1 italic tracking-widest">
                        <?= htmlspecialchars($subscription['billing_cycle']) ?></p>
                </div>
                <div class="bg-white/[0.03] p-8 rounded-[32px] border border-white/10 backdrop-blur-md">
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-2 italic">Subscription Fee
                    </p>
                    <p class="text-xl font-black text-white italic tracking-tight italic">
                        ₱<?= number_format($subscription['price'], 2) ?></p>
                    <p class="text-[10px] text-gray-500 font-black uppercase mt-1 italic tracking-widest italic">Total
                        Platform Fee</p>
                </div>
                <div class="bg-white/[0.03] p-8 rounded-[32px] border border-white/10 backdrop-blur-md">
                    <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-2 italic">Activation Info
                    </p>
                    <p class="text-xl font-black text-white italic tracking-tight italic">
                        <?= date('M d, Y', strtotime($subscription['start_date'])) ?></p>
                    <p class="text-[10px] text-gray-500 font-black uppercase mt-1 italic tracking-widest italic">Contract
                        Commencement</p>
                </div>
            </div>
        <?php else: ?>
            <div
                class="mt-12 bg-white/[0.02] p-12 rounded-[40px] border-2 border-dashed border-white/5 flex flex-col items-center justify-center text-center italic relative z-10">
                <span
                    class="material-symbols-outlined text-5xl text-gray-700 mb-4 transition-transform group-hover:scale-110">credit_card</span>
                <p class="text-sm font-bold text-gray-500 uppercase tracking-widest italic">No matching client subscription
                    found for this user.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Compliance Section (Kept unchanged but improved layout) -->
    <div class="glass-card p-8 mb-10">
        <h3 class="text-sm font-black italic uppercase tracking-widest mb-6 text-primary border-b border-white/5 pb-4">
            Compliance Information</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div>
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">BIR TIN</p>
                <p class="text-sm font-bold text-white"><?= htmlspecialchars($app['bir_number']) ?></p>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Business Permit No.</p>
                <p class="text-sm font-bold text-white"><?= htmlspecialchars($app['business_permit_no']) ?></p>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Valid ID Type</p>
                <p class="text-sm font-bold text-white italic uppercase">
                    <?= formatLabel($app['owner_valid_id_type'], $friendlyNames) ?></p>
            </div>
        </div>
    </div>

    <!-- Formal Document Viewer (Full Page) -->
    <div class="glass-card p-8 mb-10">
        <h3 class="text-sm font-black italic uppercase tracking-widest mb-6 text-primary border-b border-white/5 pb-4">
            Document Verification Portfolio</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
            <?php foreach ($documents as $doc):
                $docPath = $doc['file_path'];
                if (!str_starts_with($docPath, 'data:')) {
                    if (str_starts_with($docPath, 'uploads/')) {
                        $docPath = '../' . $docPath;
                    } elseif (!str_starts_with($docPath, '../') && !str_starts_with($docPath, 'http')) {
                        $docPath = '../uploads/applications/' . $docPath;
                    }
                }
                ?>
                <div class="flex flex-col gap-4">
                    <div class="group relative bg-white/[0.02] border border-white/10 rounded-2xl overflow-hidden aspect-[4/3] cursor-zoom-in modal-img-preview shadow-lg hover:border-primary/50 transition-all"
                        data-src="<?= htmlspecialchars($docPath) ?>"
                        data-title="<?= htmlspecialchars($doc['document_type']) ?>">
                        <div class="absolute inset-0 p-2">
                            <div class="w-full h-full rounded-xl overflow-hidden bg-background-dark/50">
                                <img src="<?= htmlspecialchars($docPath) ?>"
                                    class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 opacity-60 group-hover:opacity-100">
                            </div>
                        </div>
                        <div
                            class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity bg-black/20 backdrop-blur-sm">
                            <span class="material-symbols-outlined text-primary text-4xl">fullscreen</span>
                        </div>
                    </div>
                    <div class="px-2">
                        <p class="text-xs font-black uppercase text-white tracking-[0.1em]">
                            <?= htmlspecialchars($doc['document_type']) ?></p>
                        <p class="text-[10px] font-bold text-gray-500 uppercase tracking-widest mt-0.5">Formal Verification
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($app['application_status'] === 'Pending'): ?>
        <div class="flex flex-col sm:flex-row gap-4">
            <form method="POST" action="action/process_application.php" class="flex-1">
                <input type="hidden" name="application_id" value="<?= $app_id ?>">
                <input type="hidden" name="action" value="">
                <button type="button"
                    onclick="confirmAction(this.form, 'approve', 'Approve Application', 'Are you sure you want to approve this gym? They will be granted system access immediately.')"
                    class="w-full py-4 rounded-2xl bg-emerald-500 hover:bg-emerald-600 text-white font-black italic uppercase tracking-widest shadow-lg shadow-emerald-500/20 transition-all flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined">verified</span> Approve Application
                </button>
            </form>
            <form method="POST" action="action/process_application.php" class="flex-1">
                <input type="hidden" name="application_id" value="<?= $app_id ?>">
                <input type="hidden" name="action" value="">
                <button type="button"
                    onclick="confirmAction(this.form, 'reject', 'Reject Application', 'Are you sure you want to reject this application? This will archive the request in the Rejected History tab.')"
                    class="w-full py-4 rounded-2xl bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 text-red-500 font-black italic uppercase tracking-widest transition-all flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined">cancel</span> Reject
                </button>
            </form>
        </div>

        <div id="confirmModal" class="fixed inset-0 z-[400] hidden items-center justify-center p-4 overflow-hidden">
            <div id="confirmBackdrop" onclick="closeConfirmModal()"
                class="absolute inset-0 bg-black/40 backdrop-blur-xl transition-opacity duration-300 opacity-0"></div>
            <div id="confirmContainer"
                class="relative w-full max-w-md bg-zinc-900/90 backdrop-blur-2xl border border-white/10 shadow-2xl rounded-[32px] overflow-hidden transition-all duration-300 scale-95 opacity-0">
                <div class="p-8 text-center text-white">
                    <div
                        class="size-16 rounded-2xl bg-primary/10 border border-primary/20 flex items-center justify-center mx-auto mb-6">
                        <span class="material-symbols-outlined text-3xl text-primary">contact_support</span>
                    </div>
                    <h3 id="confirmTitle" class="text-xl font-black italic uppercase tracking-tighter mb-2 italic">Confirm Action</h3>
                    <p id="confirmMessage" class="text-gray-400 text-xs font-medium leading-relaxed mb-8"></p>

                    <div class="flex gap-3">
                        <button onclick="closeConfirmModal()"
                            class="flex-1 py-3 px-6 rounded-xl bg-white/5 hover:bg-white/10 border border-white/5 text-[10px] font-black uppercase tracking-widest transition-all text-gray-400 hover:text-white">
                            Cancel
                        </button>
                        <button onclick="executeConfirmedAction()"
                            class="flex-1 py-3 px-6 rounded-xl bg-primary hover:bg-primary/90 text-white text-[10px] font-black uppercase italic tracking-widest shadow-lg shadow-primary/20 transition-all active:scale-[0.98]">
                            Confirm
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            let pendingForm = null;

            function confirmAction(form, actionValue, title, message) {
                pendingForm = form;
                let actionInput = form.querySelector('input[name="action"]');
                if (actionInput) actionInput.value = actionValue;

                document.getElementById('confirmTitle').textContent = title;
                document.getElementById('confirmMessage').textContent = message;

                const modal = document.getElementById('confirmModal');
                modal.classList.replace('hidden', 'flex-important');
                setTimeout(() => {
                    document.getElementById('confirmBackdrop').classList.replace('opacity-0', 'opacity-100');
                    document.getElementById('confirmContainer').classList.replace('scale-95', 'scale-100');
                    document.getElementById('confirmContainer').classList.replace('opacity-0', 'opacity-100');
                }, 10);
            }

            function closeConfirmModal() {
                const modal = document.getElementById('confirmModal');
                const backdrop = document.getElementById('confirmBackdrop');
                const container = document.getElementById('confirmContainer');

                backdrop.classList.replace('opacity-100', 'opacity-0');
                container.classList.replace('scale-100', 'scale-95');
                container.classList.replace('opacity-100', 'opacity-0');

                setTimeout(() => {
                    modal.classList.replace('flex-important', 'hidden');
                    pendingForm = null;
                }, 300);
            }

            function executeConfirmedAction() {
                if (pendingForm) pendingForm.submit();
            }
        </script>
    <?php else: ?>
        <div class="glass-card p-6 border-white/10 bg-white/5 flex items-center justify-between">
            <div>
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Reviewed By</p>
                <p class="text-sm font-black italic uppercase">
                    <?= htmlspecialchars($app['reviewer_first'] . ' ' . $app['reviewer_last']) ?></p>
            </div>
            <div class="text-right">
                <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Reviewed At</p>
                <p class="text-sm font-black italic uppercase"><?= date('M d, Y h:i A', strtotime($app['reviewed_at'])) ?>
                </p>
            </div>
        </div>
    <?php endif; ?>
    </main>
    </div>

    <?php include '../includes/image_viewer.php'; ?>
</body>

</html>