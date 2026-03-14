<?php
session_start();
?>

<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" name="viewport"/>
    <title>Horizon | Multi-Tenant Management System</title>
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#7f13ec",
                        "primary-dark": "#5e0eb3",
                        "background-dark": "#0f0d12", 
                        "surface-dark": "#1a1621",
                        "text-secondary": "#ab9db9"
                    },
                    fontFamily: { "display": ["Lexend", "sans-serif"] },
                },
            },
        }
    </script>
    <style>
        html { scroll-behavior: smooth; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        .hero-pattern {
            background-color: #0f0d12;
            background-image: linear-gradient(rgba(15, 13, 18, 0.85), rgba(15, 13, 18, 0.7), rgba(15, 13, 18, 0.95)), url('https://images.unsplash.com/photo-1534438327276-14e5300c3a48?q=80&w=2070&auto=format&fit=crop');
            background-size: cover;
            background-position: center;
        }
    </style>
</head>
<body class="bg-background-dark font-display text-white overflow-x-hidden transition-colors duration-300 antialiased">
<div class="relative flex flex-col min-h-screen w-full">
    
    <nav class="sticky top-0 z-50 w-full border-b border-white/5 bg-background-dark/95 backdrop-blur-md">
        <div class="max-w-[1280px] mx-auto px-4 sm:px-10 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex items-center justify-center size-10 rounded-lg bg-primary/20 text-primary border border-primary/30">
                        <span class="material-symbols-outlined text-2xl">wb_twilight</span>
                    </div>
                    <h2 class="text-xl font-bold tracking-tight text-white uppercase italic">Horizon <span class="text-primary">Systems</span></h2>
                </div>
                
                <div class="hidden md:flex flex-1 justify-end items-center gap-8">
                    <nav class="flex items-center gap-8 text-sm font-medium">
                        <a class="hover:text-primary transition-colors text-white" href="#">Solution</a>
                        <a class="hover:text-primary transition-colors text-gray-400" href="#features">Features</a>
                    </nav>
                    <div class="flex gap-3">
                        <a href="login.php" class="flex h-10 px-5 items-center justify-center rounded-lg border border-white/10 hover:bg-white/5 text-sm font-bold transition-all">Staff Login</a>
                        <a href="tenant/tenant_application.php" class="flex h-10 px-5 items-center justify-center rounded-lg bg-primary hover:bg-primary-dark text-white text-sm font-bold shadow-lg shadow-primary/20 transition-all">Register Gym</a>
                    </div>
                </div>

                <button class="md:hidden text-white" id="mobile-toggle">
                    <span class="material-symbols-outlined text-3xl">menu</span>
                </button>
            </div>
        </div>
    </nav>

    <main class="flex-1 flex flex-col items-center">
        <section class="w-full max-w-[1400px] px-4 py-6">
            <div class="relative w-full rounded-3xl overflow-hidden min-h-[550px] sm:min-h-[650px] flex flex-col items-center justify-center text-center p-6 hero-pattern border border-white/5">
                <div class="absolute top-0 left-0 w-full h-full bg-gradient-to-b from-primary/10 via-transparent to-background-dark/95 pointer-events-none"></div>
                
                <div class="relative z-10 flex flex-col gap-6 sm:gap-8 max-w-4xl">
                    <div class="inline-flex items-center justify-center gap-2 px-4 py-1.5 rounded-full bg-white/5 border border-white/10 backdrop-blur-md w-fit mx-auto">
                        <span class="material-symbols-outlined text-primary text-sm">rocket_launch</span>
                        <span class="text-gray-300 text-[10px] font-black uppercase tracking-[0.2em]">Next-Gen Multi-Tenant Platform</span>
                    </div>
                    
                    <h1 class="text-4xl sm:text-7xl md:text-8xl font-black leading-[1] sm:leading-[0.9] tracking-tighter text-white uppercase italic">
                        Expand Your <br/>
                        <span class="text-transparent bg-clip-text bg-gradient-to-r from-primary to-[#b06bfd]">Horizon</span>
                    </h1>
                    
                    <p class="text-sm sm:text-lg text-gray-400 font-medium leading-relaxed max-w-2xl mx-auto italic px-4">
                        A robust management architecture built for fitness entrepreneurs. Isolate your data and scale your business across unlimited locations.
                    </p>
                    
                    <div class="flex flex-col sm:flex-row gap-4 justify-center mt-4 px-8 sm:px-0">
                        <a href="tenant/tenant_application.php" class="h-14 px-10 rounded-xl bg-primary hover:bg-primary-dark text-white text-sm font-black uppercase tracking-widest transition-all shadow-xl shadow-primary/40 transform hover:-translate-y-1 flex items-center justify-center">
                            Start Your Journey
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <section id="features" class="w-full max-w-[1280px] px-4 sm:px-10 py-10 sm:py-16">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 sm:gap-8">
                <div class="p-8 rounded-2xl bg-surface-dark border border-white/5 flex flex-col gap-4 hover:border-primary/50 transition-all">
                    <span class="material-symbols-outlined text-primary text-4xl">shield_person</span>
                    <h3 class="text-xl font-black uppercase italic">Safe-Guard Isolation</h3>
                    <p class="text-sm text-text-secondary leading-relaxed">Advanced tenant isolation protocols ensuring that every gym's database remains strictly private and secure.</p>
                </div>
                <div class="p-8 rounded-2xl bg-surface-dark border border-white/5 flex flex-col gap-4 hover:border-primary/50 transition-all">
                    <span class="material-symbols-outlined text-primary text-4xl">dashboard_customize</span>
                    <h3 class="text-xl font-black uppercase italic">Universal Portal</h3>
                    <p class="text-sm text-text-secondary leading-relaxed">A sleek, high-performance athlete interface designed to give your members a premium management experience.</p>
                </div>
                <div class="p-8 rounded-2xl bg-surface-dark border border-white/5 flex flex-col gap-4 hover:border-primary/50 transition-all">
                    <span class="material-symbols-outlined text-primary text-4xl">account_balance_wallet</span>
                    <h3 class="text-xl font-black uppercase italic">Verified Revenue</h3>
                    <p class="text-sm text-text-secondary leading-relaxed">Integrated payment logic that handles membership verifications and financial tracking across all tenants.</p>
                </div>
            </div>
        </section>

        <section id="memberships" class="w-full max-w-[1280px] px-4 sm:px-10 py-16 border-t border-white/5">
            <div class="text-center mb-16">
                <h2 class="text-3xl sm:text-5xl font-black uppercase italic tracking-tighter mb-4">Horizon <span class="text-primary">Partners</span></h2>
                <p class="text-gray-400 max-w-xl mx-auto">Scalable solutions for independent gyms and large-scale fitness franchises.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="p-8 rounded-3xl bg-surface-dark border border-white/5 flex flex-col hover:border-primary/50 transition-all relative overflow-hidden group">
                    <h3 class="text-xl font-black uppercase italic text-gray-300">Basic Horizon</h3>
                    <div class="my-6">
                        <span class="text-4xl font-black text-white">Entry</span>
                    </div>
                    <ul class="space-y-4 text-sm text-gray-400 mb-8 flex-1">
                        <li class="flex items-center gap-3"><span class="material-symbols-outlined text-primary text-sm">check</span> Single Location Access</li>
                        <li class="flex items-center gap-3"><span class="material-symbols-outlined text-primary text-sm">check</span> Basic Data Analytics</li>
                        <li class="flex items-center gap-3"><span class="material-symbols-outlined text-primary text-sm">check</span> Secure Tenant ID</li>
                    </ul>
                    <a href="tenant/tenant_application.php" class="w-full py-4 rounded-xl border border-white/10 text-white font-bold uppercase text-xs tracking-widest hover:bg-white/5 text-center transition-all">Register Now</a>
                </div>

                <div class="p-8 rounded-3xl bg-primary/5 border border-primary/30 flex flex-col relative overflow-hidden shadow-2xl shadow-primary/10">
                    <div class="absolute top-0 right-0 bg-primary text-white text-[10px] font-black uppercase px-4 py-2 rounded-bl-xl tracking-widest">Recommended</div>
                    <h3 class="text-xl font-black uppercase italic text-white">Business Prime</h3>
                    <div class="my-6">
                        <span class="text-4xl font-black text-white">Scale</span>
                    </div>
                    <ul class="space-y-4 text-sm text-gray-300 mb-8 flex-1">
                        <li class="flex items-center gap-3"><span class="material-symbols-outlined text-primary text-sm">check</span> Multi-Tenant Management</li>
                        <li class="flex items-center gap-3"><span class="material-symbols-outlined text-primary text-sm">check</span> Advanced Revenue Reports</li>
                        <li class="flex items-center gap-3"><span class="material-symbols-outlined text-primary text-sm">check</span> Priority Server Uptime</li>
                    </ul>
                    <a href="tenant/tenant_application.php" class="w-full py-4 rounded-xl bg-primary hover:bg-primary-dark text-white font-bold uppercase text-xs tracking-widest text-center transition-all shadow-lg">Onboard Now</a>
                </div>

                <div class="p-8 rounded-3xl bg-surface-dark border border-white/5 flex flex-col hover:border-primary/50 transition-all relative overflow-hidden group">
                    <h3 class="text-xl font-black uppercase italic text-gray-300">Enterprise</h3>
                    <div class="my-6">
                        <span class="text-4xl font-black text-white">Global</span>
                    </div>
                    <ul class="space-y-4 text-sm text-gray-400 mb-8 flex-1">
                        <li class="flex items-center gap-3"><span class="material-symbols-outlined text-primary text-sm">check</span> API Access for Integration</li>
                        <li class="flex items-center gap-3"><span class="material-symbols-outlined text-primary text-sm">check</span> Custom Security Protocols</li>
                        <li class="flex items-center gap-3"><span class="material-symbols-outlined text-primary text-sm">check</span> Dedicated Success Manager</li>
                    </ul>
                    <a href="#" class="w-full py-4 rounded-xl border border-white/10 text-white font-bold uppercase text-xs tracking-widest hover:bg-white/5 text-center transition-all">Contact Sales</a>
                </div>
            </div>
        </section>
    </main>

    <footer class="w-full border-t border-white/5 bg-background-dark py-10 mt-auto">
        <div class="max-w-[1280px] mx-auto px-4 sm:px-10 flex flex-col md:flex-row justify-between items-center gap-8">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-2xl">wb_twilight</span>
                <span class="text-sm font-black uppercase italic text-white">Horizon Systems</span>
            </div>
            <p class="text-text-secondary text-[10px] uppercase tracking-widest font-bold">© 2026 Horizon Multi-Tenant Solutions. All rights reserved.</p>
        </div>
    </footer>
</div>

<script>
    document.getElementById('mobile-toggle').addEventListener('click', function() {
        document.getElementById('mobile-menu').classList.toggle('hidden');
    });
</script>
</body>
</html>