<?php
session_start();
require_once '../db.php';

// Security Check
$role = strtolower($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id']) || ($role !== 'staff' && $role !== 'coach')) {
    header("Location: ../login.php");
    exit;
}

$gym_id = $_SESSION['gym_id'];
$adminName = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Transaction Management | Herdoza Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:ital,wght@0,300..900;1,300..900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: { colors: { "primary": "#8c2bee", "background-dark": "#0a090d", "surface-dark": "#14121a", "border-subtle": "rgba(255,255,255,0.05)"}}}
        }
    </script>
    <script>
        function updateHeaderClock() {
            const now = new Date();
            const clockEl = document.getElementById('headerClock');
            if (clockEl) {
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }
        
        window.onload = function() { 
            setInterval(updateHeaderClock, 1000); 
            updateHeaderClock(); 
        };
    </script>
    <style>
        body { font-family: 'Lexend', sans-serif; background-color: #0a090d; color: white; display: flex; flex-row: row; min-h-screen: 100vh; }
        .glass-card { background: #14121a; border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; }
        
        /* Sidebar Hover Logic - MATCHING SUPER ADMIN */
        .side-nav {
            width: 110px; 
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            z-index: 50;
        }
        .side-nav:hover {
            width: 300px; 
        }

        /* Main Content Adjustment */
        .main-content {
            margin-left: 110px; /* Base margin */
            flex: 1;
            min-width: 0;
            transition: margin-left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .side-nav:hover ~ .main-content {
            margin-left: 300px; /* Expand margin when sidebar is hovered */
        }

        .nav-label {
            opacity: 0;
            transform: translateX(-15px);
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            pointer-events: none;
        }
        .side-nav:hover .nav-label {
            opacity: 1;
            transform: translateX(0);
            pointer-events: auto;
        }

        .nav-section-label {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            margin: 0 !important;
            pointer-events: none;
        }
        .side-nav:hover .nav-section-label {
            max-height: 20px;
            opacity: 1;
            margin-bottom: 0px !important; 
            pointer-events: auto;
        }
        .side-nav:hover .mt-0 { margin-top: 0px !important; } 

        .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 10px; transition: all 0.2s ease; text-decoration: none; white-space: nowrap; }
        .nav-item:hover { background: rgba(255,255,255,0.05); }
        .nav-item.active { color: #8c2bee !important; background: rgba(140,43,238,0.1); border: 1px solid rgba(140,43,238,0.15); }
        .tab-btn { position: relative; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        
        /* Custom Inputs */
        .custom-input { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: white; transition: all 0.2s ease; }
        .custom-input:focus { outline: none; border-color: #8c2bee; }
        
        /* Status Badges */
        .badge-outline { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.1); }
    </style>
</head>
<body class="antialiased min-h-screen">

<nav class="side-nav flex flex-col fixed left-0 top-0 h-screen bg-[#0a090d] border-r border-white/5 z-50">
    <div class="px-4 py-6">
        <div class="flex items-center gap-3">
            <div class="size-10 rounded-xl bg-primary flex items-center justify-center shadow-lg shrink-0">
                <span class="material-symbols-outlined text-white text-2xl">bolt</span>
            </div>
            <span class="nav-label text-white font-black italic uppercase tracking-tighter text-base leading-none">Herdoza</span>
        </div>
    </div>
    <div class="flex flex-col flex-1 overflow-y-auto no-scrollbar px-3 gap-0.5">
        <span class="nav-section-label text-[9px] font-black text-gray-500 uppercase tracking-widest px-3 mt-0">Main Menu</span>
        <a href="admin_dashboard.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">grid_view</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Dashboard</span>
        </a>
        <a href="register_member.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">person_add</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Walk-in Member</span>
        </a>
        <a href="admin_users.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">group</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">My Users</span>
        </a>

        <span class="nav-section-label text-[9px] font-black text-gray-500 uppercase tracking-widest px-3">Management</span>
        <a href="admin_transaction.php" class="nav-item active text-primary">
            <span class="material-symbols-outlined text-xl shrink-0">receipt_long</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Transactions</span>
        </a>
        <a href="admin_appointment.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">event_note</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Bookings</span>
        </a>
        <a href="admin_attendance.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">history</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Attendance</span>
        </a>
        <a href="admin_report.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">description</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Reports</span>
        </a>
    </div>
    <div class="px-3 pt-4 pb-4 border-t border-white/10 flex flex-col gap-0.5">
        <span class="nav-section-label text-[9px] font-black text-gray-500 uppercase tracking-widest px-3 mt-0 mb-2">Account</span>
        <a href="admin_profile.php" class="nav-item text-gray-400 hover:text-white">
            <span class="material-symbols-outlined text-xl shrink-0">person</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Profile</span>
        </a>
        <a href="../logout.php" class="nav-item text-gray-400 hover:text-red-500 group">
            <span class="material-symbols-outlined text-xl shrink-0 group-hover:translate-x-1 transition-transform">logout</span>
            <span class="nav-label font-black text-[10px] uppercase tracking-wider">Sign Out</span>
        </a>
    </div>
</nav>

<div class="main-content flex flex-col min-w-0">
    <main class="flex-1 p-6 md:p-10 max-w-[1400px] w-full mx-auto">
        
        <header class="mb-10 flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
            <div>
                <h1 class="text-3xl font-black text-white italic uppercase tracking-tighter mb-1">Transaction <span class="text-primary">History</span></h1>
                <p class="text-primary text-xs font-bold uppercase tracking-widest">Billing Log • Active Pending Records</p>
            </div>
            <div class="flex flex-row items-center gap-4">
                <div class="px-6 py-3.5 rounded-2xl bg-white/5 border border-white/5 text-right flex flex-col items-end group shadow-sm hover:shadow-primary/10 transition-shadow">
                    <p id="headerClock" class="text-white font-black italic text-xl leading-none mb-1 group-hover:text-primary transition-colors">00:00:00 AM</p>
                    <p class="text-gray-500 text-[9px] font-black uppercase tracking-[0.2em] group-hover:text-white transition-colors"><?= date('l, M d') ?></p>
                </div>
            </div>
        </header>

        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6 border-b border-white/5 pb-4">
            <div class="flex gap-8">
                <button class="pb-4 -mb-4 text-[11px] font-black uppercase tracking-widest border-b-2 border-primary text-primary transition-all">BILLING HISTORY</button>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <div class="relative">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-sm">search</span>
                    <input type="text" placeholder="Search member..." class="custom-input rounded-xl pl-9 pr-4 py-2 text-xs w-48 font-medium">
                </div>
                
                <input type="date" class="custom-input rounded-xl px-3 py-2 text-xs font-medium text-gray-300 w-36 uppercase">
                <span class="text-gray-500 text-[10px] font-black uppercase tracking-wider">TO</span>
                <input type="date" class="custom-input rounded-xl px-3 py-2 text-xs font-medium text-gray-300 w-36 uppercase">
                
                <button class="custom-input rounded-xl px-6 py-2 text-[10px] font-black uppercase tracking-widest hover:bg-white/5">APPLY</button>
                
                <div class="h-6 w-px bg-white/10 mx-1"></div>
                
                <button class="custom-input rounded-xl px-4 py-2 text-[10px] font-black uppercase tracking-widest hover:bg-primary/10 hover:text-primary hover:border-primary/30 flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">download</span> CSV
                </button>
            </div>
        </div>

        <div id="billing-tab">
            <div class="glass-card overflow-hidden">
                <div class="px-8 py-5 border-b border-white/5">
                    <h2 class="text-gray-400 text-[11px] font-black italic uppercase tracking-widest">TRANSACTION HISTORY LOG</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="text-gray-500 text-[9px] font-black uppercase tracking-[0.15em] border-b border-white/5">
                                <th class="px-8 py-4">MEMBER DETAILS</th>
                                <th class="px-8 py-4 text-center">AMOUNT</th>
                                <th class="px-8 py-4 text-center">PAYMENT TYPE</th>
                                <th class="px-8 py-4">DATE & TIME</th>
                                <th class="px-8 py-4 text-right">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-8 py-4">
                                    <div class="flex items-center gap-4">
                                        <div class="size-9 rounded-full bg-primary/20 flex items-center justify-center font-black text-primary text-sm shadow-lg border border-primary/30">J</div>
                                        <div>
                                            <p class="text-white font-black uppercase italic text-sm tracking-tight">JOHN DOE</p>
                                            <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mt-0.5">@J.DOE</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-8 py-4 text-center">
                                    <span class="text-[10px] font-black badge-outline px-4 py-1.5 rounded-full uppercase tracking-widest text-white">₱1,000.00</span>
                                </td>
                                <td class="px-8 py-4 text-center">
                                    <span class="text-[10px] font-black badge-outline px-4 py-1.5 rounded-full uppercase tracking-widest text-gray-300">GCASH</span>
                                </td>
                                <td class="px-8 py-4">
                                    <div class="flex items-center gap-2">
                                        <p class="text-white font-black italic text-xs tracking-wide">01:00 PM</p>
                                        <span class="text-gray-600 text-[10px] font-black">→</span>
                                        <p class="text-gray-500 text-[10px] font-bold uppercase tracking-widest">JAN 01, 2024</p>
                                    </div>
                                </td>
                                <td class="px-8 py-4 text-right">
                                    <div class="flex justify-end gap-2">
                                        <button class="size-8 rounded-lg badge-outline flex items-center justify-center hover:bg-primary/20 hover:text-primary transition-all text-gray-400">
                                            <span class="material-symbols-outlined text-[16px]">check</span>
                                        </button>
                                        <button class="size-8 rounded-lg badge-outline flex items-center justify-center hover:bg-red-500/20 hover:text-red-500 transition-all text-gray-400">
                                            <span class="material-symbols-outlined text-[16px]">close</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-8 py-4">
                                    <div class="flex items-center gap-4">
                                        <div class="size-9 rounded-full bg-primary/20 flex items-center justify-center font-black text-primary text-sm shadow-lg border border-primary/30">J</div>
                                        <div>
                                            <p class="text-white font-black uppercase italic text-sm tracking-tight">JANE SMITH</p>
                                            <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mt-0.5">@J.SMITH</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-8 py-4 text-center">
                                    <span class="text-[10px] font-black badge-outline px-4 py-1.5 rounded-full uppercase tracking-widest text-white">₱1,500.00</span>
                                </td>
                                <td class="px-8 py-4 text-center">
                                    <span class="text-[10px] font-black badge-outline px-4 py-1.5 rounded-full uppercase tracking-widest text-gray-300">CASH</span>
                                </td>
                                <td class="px-8 py-4">
                                    <div class="flex items-center gap-2">
                                        <p class="text-white font-black italic text-xs tracking-wide">02:30 PM</p>
                                        <span class="text-gray-600 text-[10px] font-black">→</span>
                                        <p class="text-gray-500 text-[10px] font-bold uppercase tracking-widest">JAN 02, 2024</p>
                                    </div>
                                </td>
                                <td class="px-8 py-4 text-right">
                                    <div class="flex justify-end gap-2">
                                        <button class="size-8 rounded-lg badge-outline flex items-center justify-center hover:bg-primary/20 hover:text-primary transition-all text-gray-400">
                                            <span class="material-symbols-outlined text-[16px]">check</span>
                                        </button>
                                        <button class="size-8 rounded-lg badge-outline flex items-center justify-center hover:bg-red-500/20 hover:text-red-500 transition-all text-gray-400">
                                            <span class="material-symbols-outlined text-[16px]">close</span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>