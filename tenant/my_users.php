<?php
session_start();
require_once '../db.php';

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || !in_array($role, ['tenant', 'admin'])) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$active_tab = $_GET['tab'] ?? 'members'; // Default to members

// Fetch Coaches
$stmtCoaches = $pdo->prepare("
    SELECT u.*, s.staff_role, s.status as staff_status 
    FROM users u 
    JOIN staff s ON u.user_id = s.user_id 
    WHERE s.gym_id = ? AND s.staff_role = 'Coach'
");
$stmtCoaches->execute([$gym_id]);
$coaches = $stmtCoaches->fetchAll();

// Fetch Members
$stmtMembers = $pdo->prepare("
    SELECT u.*, m.membership_type, m.status as member_status 
    FROM users u 
    JOIN members m ON u.user_id = m.user_id 
    WHERE m.gym_id = ?
");
$stmtMembers->execute([$gym_id]);
$members = $stmtMembers->fetchAll();

// Gym Info for Sidebar
$stmtGym = $pdo->prepare("SELECT gym_name FROM gyms WHERE gym_id = ?");
$stmtGym->execute([$gym_id]);
$gym = $stmtGym->fetch();
?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8">
    <title>User Management | Horizon</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;700;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "bg-dark": "#050505", "card-dark": "#121212" }}}
        }
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #050505; color: white; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .tab-active { color: #8c2bee; border-bottom: 4px solid #8c2bee; }
    </style>
</head>
<body class="flex h-screen overflow-hidden">

<aside class="w-72 bg-bg-dark border-r border-white/5 p-8 flex flex-col shrink-0">
    <div class="flex items-center gap-3 mb-10">
        <div class="size-12 rounded-xl bg-primary/10 flex items-center justify-center border border-primary/20">
            <span class="material-symbols-outlined text-primary font-bold text-3xl">bolt</span>
        </div>
        <h1 class="text-xl font-black italic uppercase tracking-tighter truncate"><?= htmlspecialchars($gym['gym_name']) ?></h1>
    </div>

    <nav class="flex flex-col gap-2 flex-1">
        <a href="tenant_dashboard.php" class="flex items-center gap-4 px-4 py-3 rounded-xl text-gray-400 hover:bg-white/5 transition-all group">
            <span class="material-symbols-outlined group-hover:text-primary">grid_view</span>
            <span class="font-bold uppercase tracking-tighter text-sm italic">Dashboard</span>
        </a>
        <a href="users_management.php" class="flex items-center gap-4 px-4 py-3 rounded-xl bg-primary/10 text-primary border border-primary/20">
            <span class="material-symbols-outlined">manage_accounts</span>
            <span class="font-bold uppercase tracking-tighter text-sm italic">My Users</span>
        </a>
        <a href="staff_management.php" class="flex items-center gap-4 px-4 py-3 rounded-xl text-gray-400 hover:bg-white/5 transition-all group">
            <span class="material-symbols-outlined group-hover:text-primary">shield_person</span>
            <span class="font-bold uppercase tracking-tighter text-sm italic">Staff</span>
        </a>
    </nav>
</aside>

<main class="flex-1 overflow-y-auto no-scrollbar p-10">
    <header class="mb-10 flex justify-between items-end">
        <div>
            <h2 class="text-5xl font-black italic uppercase tracking-tighter">My <span class="text-primary">Users</span></h2>
            <p class="text-gray-500 font-bold uppercase tracking-[0.3em] text-[10px] mt-2">Manage your community roster</p>
        </div>
        <div class="flex bg-card-dark rounded-2xl p-1 border border-white/5">
            <button onclick="switchTab('members')" id="tab-btn-members" class="px-8 py-3 rounded-xl font-black italic uppercase tracking-tighter text-sm transition-all <?= $active_tab == 'members' ? 'bg-primary text-white' : 'text-gray-500 hover:text-white' ?>">Members</button>
            <button onclick="switchTab('coaches')" id="tab-btn-coaches" class="px-8 py-3 rounded-xl font-black italic uppercase tracking-tighter text-sm transition-all <?= $active_tab == 'coaches' ? 'bg-primary text-white' : 'text-gray-500 hover:text-white' ?>">Coaches</button>
        </div>
    </header>

    <div class="grid grid-cols-3 gap-6 mb-10">
        <div class="bg-card-dark p-8 rounded-[32px] border border-white/5 relative overflow-hidden group">
            <div class="absolute -right-4 -top-4 size-24 bg-primary/10 rounded-full blur-3xl group-hover:bg-primary/20 transition-all"></div>
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Total Community</p>
            <h3 class="text-4xl font-black italic tracking-tighter"><?= count($members) + count($coaches) ?></h3>
        </div>
        <div class="bg-card-dark p-8 rounded-[32px] border border-white/5">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Active Members</p>
            <h3 class="text-4xl font-black italic tracking-tighter text-emerald-500"><?= count($members) ?></h3>
        </div>
        <div class="bg-card-dark p-8 rounded-[32px] border border-white/5">
            <p class="text-[10px] font-black uppercase text-gray-500 tracking-widest mb-1">Expert Coaches</p>
            <h3 class="text-4xl font-black italic tracking-tighter text-primary"><?= count($coaches) ?></h3>
        </div>
    </div>

    <div class="bg-card-dark rounded-[32px] border border-white/5 overflow-hidden shadow-2xl">
        
        <div id="tab-members" class="<?= $active_tab == 'members' ? '' : 'hidden' ?>">
            <table class="w-full text-left">
                <thead class="bg-black/40 border-b border-white/5">
                    <tr class="text-[10px] font-black uppercase tracking-widest text-gray-500">
                        <th class="px-8 py-5">Member Name</th>
                        <th class="px-8 py-5">Plan</th>
                        <th class="px-8 py-5">Status</th>
                        <th class="px-8 py-5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach($members as $m): ?>
                    <tr class="hover:bg-white/[0.02] transition-all group">
                        <td class="px-8 py-6 flex items-center gap-4">
                            <div class="size-10 rounded-full bg-primary/20 border border-primary/30 flex items-center justify-center text-primary font-black italic text-xs">
                                <?= strtoupper(substr($m['first_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <p class="font-black italic uppercase tracking-tighter text-sm"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?></p>
                                <p class="text-[10px] text-gray-500 font-bold"><?= htmlspecialchars($m['email']) ?></p>
                            </div>
                        </td>
                        <td class="px-8 py-6">
                            <span class="text-[10px] font-black uppercase italic tracking-tighter text-gray-400"><?= $m['membership_type'] ?></span>
                        </td>
                        <td class="px-8 py-6">
                            <span class="px-3 py-1 rounded-full text-[8px] font-black uppercase tracking-widest border <?= $m['member_status'] == 'Active' ? 'bg-emerald-500/10 text-emerald-500 border-emerald-500/20' : 'bg-red-500/10 text-red-500 border-red-500/20' ?>">
                                <?= $m['member_status'] ?>
                            </span>
                        </td>
                        <td class="px-8 py-6 text-right">
                            <button class="size-8 rounded-lg bg-white/5 hover:bg-primary transition-all text-gray-500 hover:text-white">
                                <span class="material-symbols-outlined text-sm">visibility</span>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div id="tab-coaches" class="<?= $active_tab == 'coaches' ? '' : 'hidden' ?>">
            <table class="w-full text-left">
                <thead class="bg-black/40 border-b border-white/5">
                    <tr class="text-[10px] font-black uppercase tracking-widest text-gray-500">
                        <th class="px-8 py-5">Coach Name</th>
                        <th class="px-8 py-5">Specialization</th>
                        <th class="px-8 py-5">System Role</th>
                        <th class="px-8 py-5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php foreach($coaches as $c): ?>
                    <tr class="hover:bg-white/[0.02] transition-all group">
                        <td class="px-8 py-6 flex items-center gap-4">
                            <div class="size-10 rounded-full bg-amber-500/20 border border-amber-500/30 flex items-center justify-center text-amber-500 font-black italic text-xs">
                                <?= strtoupper(substr($c['first_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <p class="font-black italic uppercase tracking-tighter text-sm"><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></p>
                                <p class="text-[10px] text-gray-500 font-bold italic tracking-widest">VERIFIED COACH</p>
                            </div>
                        </td>
                        <td class="px-8 py-6">
                            <span class="text-[10px] font-black uppercase italic tracking-tighter text-gray-400">Personal Trainer</span>
                        </td>
                        <td class="px-8 py-6">
                            <span class="px-3 py-1 rounded-full text-[8px] font-black uppercase tracking-widest border bg-primary/10 text-primary border-primary/20">
                                Staff Member
                            </span>
                        </td>
                        <td class="px-8 py-6 text-right">
                            <button class="size-8 rounded-lg bg-white/5 hover:bg-primary transition-all text-gray-500 hover:text-white">
                                <span class="material-symbols-outlined text-sm">edit</span>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
</main>

<script>
    function switchTab(tabName) {
        // Elements
        const memberTab = document.getElementById('tab-members');
        const coachTab = document.getElementById('tab-coaches');
        const memberBtn = document.getElementById('tab-btn-members');
        const coachBtn = document.getElementById('tab-btn-coaches');

        if (tabName === 'members') {
            memberTab.classList.remove('hidden');
            coachTab.classList.add('hidden');
            memberBtn.classList.add('bg-primary', 'text-white');
            memberBtn.classList.remove('text-gray-500');
            coachBtn.classList.remove('bg-primary', 'text-white');
            coachBtn.classList.add('text-gray-500');
        } else {
            coachTab.classList.remove('hidden');
            memberTab.classList.add('hidden');
            coachBtn.classList.add('bg-primary', 'text-white');
            coachBtn.classList.remove('text-gray-500');
            memberBtn.classList.remove('bg-primary', 'text-white');
            memberBtn.classList.add('text-gray-500');
        }
    }
</script>

</body>
</html>