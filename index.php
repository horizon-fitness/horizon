<?php
session_start();
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" name="viewport"/>
    <title>Horizon | Multi-Tenant Management System</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com"/>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin=""/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600&family=Lexend:wght@300;400;500;700;900&display=swap" rel="stylesheet"/>
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
                        "background-dark": "#050505", 
                        "surface-dark": "rgba(21, 21, 24, 0.4)",
                        "text-secondary": "#ab9db9"
                    },
                    fontFamily: { 
                        "display": ["Lexend", "sans-serif"],
                        "sans": ["Plus Jakarta Sans", "Inter", "sans-serif"]
                    },
                    borderRadius: { 'custom': '12px' }
                },
            },
        }
    </script>
    <style>
        html { scroll-behavior: smooth; }
        body { background-color: #050505; color: #f3f4f6; }
        
        .glass-nav {
            background: transparent;
            border-bottom: 1px solid transparent;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .glass-nav.scrolled {
            background: rgba(5, 5, 5, 0.8);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding-top: 0.75rem;
            padding-bottom: 0.75rem;
        }

        .nav-link {
            position: relative;
            transition: color 0.3s ease;
        }
        .nav-link::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: -4px;
            left: 0;
            background-color: #7f13ec;
            transition: width 0.3s ease;
        }
        .nav-link:hover::after, .nav-link:focus::after {
            width: 100%;
        }
        .nav-link:hover {
            color: white;
        }

        .dashboard-window {
            background: #08080a;
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 1);
        }

        .metric-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 1.25rem;
            position: relative;
        }

        .text-gradient {
            background: linear-gradient(to right, #ffffff 10%, #bf80ff 50%, #7f13ec 95%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: inline-block;
            padding-right: 0.25em;
            margin-right: -0.25em;
            filter: drop-shadow(0 0 25px rgba(127, 19, 236, 0.4));
        }

        .hero-glow {
            background: radial-gradient(circle at 50% -10%, rgba(127, 19, 236, 0.18), transparent 70%);
        }

        .plan-card {
            background: #0d0d10;
            border: 1px solid rgba(255, 255, 255, 0.05);
            transition: all 0.3s ease;
        }
        .plan-card:hover {
            border-color: #7f13ec;
            transform: translateY(-5px);
        }
    </style>
</head>
<body class="font-sans antialiased overflow-x-hidden">

    <nav id="topNav" class=" glass-nav fixed top-0 w-full z-50">
        <div class="max-w-7xl mx-auto px-6 py-4 transition-all duration-300">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-12">
                    <div class="flex items-center gap-3">
                        <div class="size-10 bg-primary/20 rounded-lg flex items-center justify-center border border-primary/30">
                            <span class="material-symbols-outlined text-primary">blur_on</span>
                        </div>
                        <h2 class="text-xl font-display font-bold text-white uppercase italic tracking-tighter">Horizon <span class="text-primary">System</span></h2>
                    </div>

                    <div class="hidden md:flex items-center gap-8 text-[11px] font-display font-bold uppercase tracking-widest text-gray-500">
                        <a href="#" class="nav-link">Home</a>
                        <a href="#about" class="nav-link">About Us</a>
                        <a href="#plans" class="nav-link">Plan</a>
                        <a href="#contact" class="nav-link">Contact</a>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <a href="login.php" class="font-display bg-white/5 hover:bg-white/10 text-white border border-white/10 px-5 py-2.5 rounded-custom text-[11px] font-bold uppercase tracking-widest transition-all">
                        Staff Login
                    </a>
                    <a href="tenant/tenant_application.php" class="font-display bg-primary hover:bg-primary-dark text-white px-5 py-2.5 rounded-custom text-[11px] font-bold uppercase tracking-widest transition-all shadow-lg shadow-primary/20">
                        Register Gym
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <main class="hero-glow">
        <section class="relative pt-20 pb-20 md:pt-32 md:pb-40 px-6 flex items-center justify-center">
            <div class="max-w-7xl w-full grid lg:grid-cols-2 gap-16 items-center relative z-10">
                <div class="text-left">
                    <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/5 border border-white/10 text-primary text-[10px] font-black uppercase tracking-[0.2em] mb-8">
                        <span class="material-symbols-outlined text-sm">hub</span>
                        Next-Gen Multi-Tenant Platform
                    </div>
                    
                    <h1 class="text-6xl md:text-8xl font-display font-black leading-[0.85] tracking-tighter text-white uppercase italic mb-8">
                        Expand Your <br/>
                        <span class="text-gradient">Horizon</span>
                    </h1>
                    
                    <p class="text-lg text-gray-500 font-medium leading-relaxed max-w-md mb-10 italic">
                        Together with <span class="text-white font-bold">HORIZON</span>, your fitness business will really form and scale. Interested? Join now!
                    </p>
                    
                    <div class="flex gap-4 mb-16">
                        <a href="tenant/tenant_application.php" class="font-display px-10 py-5 bg-primary text-white font-bold rounded-custom text-xs uppercase tracking-widest hover:scale-105 transition-all shadow-xl shadow-primary/20">Join us</a>
                    </div>

                    <div class="flex gap-12 border-t border-white/5 pt-10">
                        <div>
                            <h3 class="text-3xl font-display font-black text-primary">28</h3>
                            <p class="text-[10px] text-gray-600 uppercase font-black tracking-widest mt-1">Exercise Programs</p>
                        </div>
                        <div>
                            <h3 class="text-3xl font-display font-black text-white">980+</h3>
                            <p class="text-[10px] text-gray-600 uppercase font-black tracking-widest mt-1">Total Members</p>
                        </div>
                        <div>
                            <h3 class="text-3xl font-display font-black text-white">180+</h3>
                            <p class="text-[10px] text-gray-600 uppercase font-black tracking-widest mt-1">Professional Coaches</p>
                        </div>
                    </div>
                </div>

                <div class="relative">
                    <div class="dashboard-window w-full rounded-2xl p-8 overflow-hidden">
                        <div class="flex flex-col mb-10">
                            <h3 class="text-2xl font-display font-black text-white uppercase italic tracking-tight">System <span class="text-primary">Overview</span></h3>
                            <p class="text-[10px] text-gray-600 font-bold uppercase tracking-[0.3em] mt-1">Next-Gen Multi-Tenant Platform</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-8">
                            <div class="metric-card border border-emerald-500/20">
                                <p class="text-[9px] text-gray-500 uppercase font-black mb-3 tracking-widest">Global Revenue</p>
                                <div class="flex justify-between items-center">
                                    <span class="text-2xl font-black text-white">₱0.00</span>
                                    <span class="material-symbols-outlined text-emerald-500/40 text-2xl">payments</span>
                                </div>
                                <p class="text-[8px] text-emerald-500/80 uppercase font-bold mt-3">Across All Tenants</p>
                            </div>

                            <div class="metric-card border border-amber-500/20">
                                <p class="text-[9px] text-gray-500 uppercase font-black mb-3 tracking-widest">Active Tenants</p>
                                <div class="flex justify-between items-center">
                                    <span class="text-2xl font-black text-white">3 Gyms</span>
                                    <span class="material-symbols-outlined text-amber-500/40 text-2xl">domain</span>
                                </div>
                                <p class="text-[8px] text-amber-500/80 uppercase font-bold mt-3">Live Subscriptions</p>
                            </div>
                        </div>

                        <div class="metric-card border-dashed border-white/5 flex items-center justify-center h-40 bg-white/[0.01]">
                            <div class="text-center">
                                <span class="material-symbols-outlined text-gray-800 text-4xl mb-2">monitoring</span>
                                <p class="text-[10px] text-gray-700 uppercase font-black tracking-[0.4em]">Analytics Engine Active</p>
                            </div>
                        </div>
                    </div>
                    <div class="absolute -bottom-10 -left-10 w-64 h-64 bg-primary/20 blur-[100px] rounded-full pointer-events-none"></div>
                </div>
            </div>
        </section>

        <section id="about" class="py-32 px-6 relative border-t border-white/5 bg-gradient-to-b from-transparent to-black/50">
            <div class="max-w-7xl mx-auto">
                <div class="grid lg:grid-cols-2 gap-20 items-center">
                    <div>
                        <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/5 border border-white/10 text-primary text-[10px] font-black uppercase tracking-[0.2em] mb-6">
                            Behind the System
                        </div>
                        <h2 class="text-5xl font-display font-black text-white uppercase italic leading-tight mb-8">
                            One Platform.<br/>
                            <span class="text-gradient">Infinite Gyms.</span>
                        </h2>
                        <div class="space-y-6 text-gray-400 italic leading-relaxed">
                            <p>
                                Horizon is more than just a management tool; it is a multi-tenant ecosystem designed to revolutionize how fitness centers operate. We provide the digital backbone that allows gym owners to automate their workflow.
                            </p>
                            <p>
                                Our architecture ensures that every gym enjoys a private, secure environment with custom analytics and dedicated resources, all while operating under the powerful Horizon umbrella.
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-6 mt-12">
                            <div class="p-4 rounded-xl bg-white/[0.02] border border-white/5">
                                <span class="material-symbols-outlined text-primary mb-2">security</span>
                                <h4 class="text-white text-xs font-bold uppercase tracking-widest mb-1 italic">Data Isolation</h4>
                                <p class="text-[10px] text-gray-600">Your gym's data is strictly yours. Secure tenant separation at every layer.</p>
                            </div>
                            <div class="p-4 rounded-xl bg-white/[0.02] border border-white/5">
                                <span class="material-symbols-outlined text-primary mb-2">speed</span>
                                <h4 class="text-white text-xs font-bold uppercase tracking-widest mb-1 italic">High Velocity</h4>
                                <p class="text-[10px] text-gray-600">Optimized for real-time check-ins and instant membership updates.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="relative">
                        <div class="rounded-2xl overflow-hidden border border-white/10 shadow-2xl relative z-10 bg-surface-dark p-2">
                            <img src="https://images.unsplash.com/photo-1534438327276-14e5300c3a48?q=80&w=2070&auto=format&fit=crop" alt="Gym" class="w-full rounded-xl grayscale hover:grayscale-0 transition-all duration-1000">
                        </div>
                        <div class="absolute -bottom-10 -left-10 w-48 h-48 rounded-2xl overflow-hidden border border-white/10 z-20 hidden md:block">
                            <img src="https://images.unsplash.com/photo-1593079831268-3381b0db4a77?q=80&w=2069&auto=format&fit=crop" class="w-full h-full object-cover">
                        </div>
                        <div class="absolute -top-6 -right-6 w-32 h-32 bg-primary/30 blur-3xl rounded-full"></div>
                    </div>
                </div>
            </div>
        </section>

        <section id="plans" class="py-32 px-6 relative border-t border-white/5">
            <div class="max-w-7xl mx-auto text-center">
                <div class="mb-16">
                    <div class="inline-flex items-center justify-center p-3 rounded-xl bg-primary/10 border border-primary/20 mb-6">
                        <span class="material-symbols-outlined text-primary">workspace_premium</span>
                    </div>
                    <h2 class="text-4xl md:text-5xl font-display font-black text-white uppercase italic tracking-tighter mb-4">
                        Choose Your <span class="text-primary">Growth Plan</span>
                    </h2>
                    <p class="text-[10px] text-gray-500 font-bold uppercase tracking-[0.3em]">Select a plan to activate your gym's digital infrastructure</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div class="plan-card rounded-2xl p-10 flex flex-col text-left">
                        <h3 class="text-xl font-display font-black text-white uppercase italic mb-1">6-Month Kickstart</h3>
                        <p class="text-[9px] text-gray-600 font-bold uppercase tracking-widest mb-8">6 Months Billing</p>
                        <div class="mb-10">
                            <span class="text-4xl font-display font-black text-white">₱4,999</span>
                            <span class="text-[10px] text-gray-600 font-bold uppercase tracking-widest">/ Term</span>
                        </div>
                        <ul class="space-y-4 mb-12 flex-grow">
                            <li class="flex items-center gap-3 text-xs text-gray-400 font-medium">
                                <span class="material-symbols-outlined text-primary text-sm">check_circle</span> Multi-Tenant Management
                            </li>
                            <li class="flex items-center gap-3 text-xs text-gray-400 font-medium">
                                <span class="material-symbols-outlined text-primary text-sm">check_circle</span> Base64 Document Engine
                            </li>
                        </ul>
                        <a href="#" class="w-full py-4 border border-white/10 rounded-xl text-[10px] font-black uppercase tracking-widest text-white text-center hover:bg-white/5 transition-all">Select Plan</a>
                    </div>

                    <div class="plan-card rounded-2xl p-10 flex flex-col text-left border-primary/50 bg-primary/5 scale-105">
                        <h3 class="text-xl font-display font-black text-white uppercase italic mb-1">1-Year Momentum</h3>
                        <p class="text-[9px] text-primary font-bold uppercase tracking-widest mb-8">Most Popular</p>
                        <div class="mb-10">
                            <span class="text-4xl font-display font-black text-white">₱14,999</span>
                            <span class="text-[10px] text-gray-600 font-bold uppercase tracking-widest">/ Yr</span>
                        </div>
                        <ul class="space-y-4 mb-12 flex-grow">
                            <li class="flex items-center gap-3 text-xs text-gray-400 font-medium">
                                <span class="material-symbols-outlined text-primary text-sm">check_circle</span> Advanced Reports
                            </li>
                            <li class="flex items-center gap-3 text-xs text-gray-400 font-medium">
                                <span class="material-symbols-outlined text-primary text-sm">check_circle</span> Priority Support
                            </li>
                        </ul>
                        <a href="#" class="w-full py-4 bg-primary rounded-xl text-[10px] font-black uppercase tracking-widest text-white text-center hover:bg-primary-dark transition-all">Select Plan</a>
                    </div>

                    <div class="plan-card rounded-2xl p-10 flex flex-col text-left">
                        <h3 class="text-xl font-display font-black text-white uppercase italic mb-1">3-Year Legacy</h3>
                        <p class="text-[9px] text-gray-600 font-bold uppercase tracking-widest mb-8">3 Years Billing</p>
                        <div class="mb-10">
                            <span class="text-4xl font-display font-black text-white">₱29,999</span>
                            <span class="text-[10px] text-gray-600 font-bold uppercase tracking-widest">/ Term</span>
                        </div>
                        <ul class="space-y-4 mb-12 flex-grow">
                            <li class="flex items-center gap-3 text-xs text-gray-400 font-medium">
                                <span class="material-symbols-outlined text-primary text-sm">check_circle</span> API Integration
                            </li>
                            <li class="flex items-center gap-3 text-xs text-gray-400 font-medium">
                                <span class="material-symbols-outlined text-primary text-sm">check_circle</span> Unlimited Accounts
                            </li>
                        </ul>
                        <a href="#" class="w-full py-4 border border-white/10 rounded-xl text-[10px] font-black uppercase tracking-widest text-white text-center hover:bg-white/5 transition-all">Select Plan</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- CTA Section -->
    <section class="w-full bg-[#0a0a0c] border-y border-white/5 py-20 px-6 relative overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-r from-primary/10 via-transparent to-primary/10 opacity-30"></div>
        <div class="max-w-7xl mx-auto flex flex-col md:flex-row items-center justify-between gap-8 relative z-10">
            <div class="text-center md:text-left">
                <h2 class="text-3xl md:text-5xl font-display font-black text-white uppercase italic tracking-tighter mb-4">Ready to transform?</h2>
                <p class="text-sm text-gray-400 font-medium italic">Activate your gym's digital infrastructure today.</p>
            </div>
            <a href="tenant/tenant_application.php" class="bg-primary hover:bg-primary-dark text-white px-10 py-5 rounded-xl font-display font-bold uppercase tracking-widest text-[11px] transition-all shadow-2xl hover:scale-105 active:scale-95">Register Now</a>
        </div>
    </section>

    <!-- Main Footer -->
    <footer id="contact" class="bg-[#08080a] border-t border-white/5 pt-24 pb-12 px-6">
        <div class="max-w-7xl mx-auto">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-16 mb-24">
                <!-- Brand Column -->
                <div class="space-y-8">
                    <div>
                        <div class="flex items-center gap-3 mb-6">
                            <span class="material-symbols-outlined text-primary text-3xl">blur_on</span>
                            <h2 class="text-2xl font-display font-bold text-white uppercase italic tracking-tighter">Horizon <span class="text-primary">System</span></h2>
                        </div>
                        <p class="text-[10px] text-primary font-black uppercase tracking-[0.4em] mb-6">Expand Your Horizon</p>
                        <p class="text-xs text-gray-500 font-medium leading-relaxed italic max-w-sm">
                            Together with HORIZON, your fitness business will really form and scale.
                        </p>
                    </div>
                    
                    <div class="flex gap-4">
                        <a href="#" class="size-10 rounded-full bg-white/5 border border-white/10 flex items-center justify-center text-gray-400 hover:text-white hover:bg-primary/20 hover:border-primary/50 transition-all group">
                            <svg class="w-4 h-4 fill-current group-hover:scale-110 transition-transform" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                        </a>
                        <a href="#" class="size-10 rounded-full bg-white/5 border border-white/10 flex items-center justify-center text-gray-400 hover:text-white hover:bg-primary/20 hover:border-primary/50 transition-all group">
                            <svg class="w-4 h-4 fill-current group-hover:scale-110 transition-transform" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 1.366.062 2.633.332 3.608 1.308.975.975 1.245 2.242 1.308 3.608.058 1.266.07 1.646.07 4.85s-.012 3.584-.07 4.85c-.063 1.366-.333 2.633-1.308 3.608-.975.975-2.242 1.246-3.608 1.308-1.266.058-1.646.07-4.85.07s-3.584-.012-4.85-.07c-1.366-.062-2.633-.332-3.608-1.308-.975-.975-1.245-2.242-1.308-3.608-.058-1.266-.07-1.646-.07-4.85s.012-3.584.07-4.85c.062-1.366.332-2.633 1.308-3.608.975-.975 2.242-1.245 3.608-1.308 1.266-.058 1.646-.07 4.85-.07zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948s.014 3.667.072 4.947c.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072s3.667-.014 4.947-.072c4.358-.2 6.78-2.618 6.98-6.98.058-1.281.072-1.689.072-4.948s-.014-3.667-.072-4.947c-.2-4.358-2.618-6.78-6.98-6.98-1.28-.059-1.688-.073-4.947-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                        </a>
                    </div>
                </div>

                <!-- Quick Links Column -->
                <div class="flex flex-col gap-8">
                    <h4 class="text-sm font-display font-black text-white uppercase italic tracking-[0.2em] relative inline-block">
                        Quick Links
                        <span class="absolute -bottom-2 left-0 w-8 h-0.5 bg-primary"></span>
                    </h4>
                    <div class="flex flex-col gap-6 text-xs font-bold text-gray-500 uppercase tracking-widest">
                        <a href="#" class="hover:text-primary transition-all flex items-center gap-2 group">Home</a>
                        <a href="#about" class="hover:text-primary transition-all flex items-center gap-2 group">Programs</a>
                        <a href="#about" class="hover:text-primary transition-all flex items-center gap-2 group">About Us</a>
                        <a href="#plans" class="hover:text-primary transition-all flex items-center gap-2 group">Trainers</a>
                        <a href="login.php" class="hover:text-primary transition-all flex items-center gap-2 group">Staff Login</a>
                    </div>
                </div>

                <!-- Contact Column -->
                <div class="flex flex-col gap-8">
                    <h4 class="text-sm font-display font-black text-white uppercase italic tracking-[0.2em] relative inline-block">
                        Contact Us
                        <span class="absolute -bottom-2 left-0 w-8 h-0.5 bg-primary"></span>
                    </h4>
                    <div class="space-y-8">
                        <div class="flex items-start gap-4 group">
                            <div class="size-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-primary group-hover:bg-primary group-hover:text-white transition-all duration-500">
                                <span class="material-symbols-outlined text-xl">location_on</span>
                            </div>
                            <p class="text-xs text-gray-500 font-medium leading-relaxed italic">
                                Baliwag, Bulacan,<br/>Philippines, 3006
                            </p>
                        </div>
                        <div class="flex items-center gap-4 group">
                            <div class="size-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-primary group-hover:bg-primary group-hover:text-white transition-all duration-500">
                                <span class="material-symbols-outlined text-xl">call</span>
                            </div>
                            <p class="text-xs text-gray-500 font-medium uppercase tracking-widest">0976-241-1986</p>
                        </div>
                        <div class="flex items-center gap-4 group">
                            <div class="size-10 rounded-xl bg-white/5 border border-white/10 flex items-center justify-center text-primary group-hover:bg-primary group-hover:text-white transition-all duration-500">
                                <span class="material-symbols-outlined text-xl">mail</span>
                            </div>
                            <p class="text-xs text-gray-500 font-medium lowercase tracking-wider">horizonfitnesscorp@gmail.com</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pt-10 border-t border-white/5 text-center">
                <p class="text-[9px] font-bold text-gray-700 uppercase tracking-[0.5em]">
                    © 2026 HORIZON SYSTEM. SECURE ENVIRONMENT. ALL RIGHTS RESERVED.
                </p>
            </div>
        </div>
    </footer>

    <script>
        const nav = document.getElementById('topNav');
        window.onscroll = function() {
            if (window.scrollY > 50) {
                nav.classList.add('scrolled');
            } else {
                nav.classList.remove('scrolled');
            }
        };
    </script>
</body>
</html>
