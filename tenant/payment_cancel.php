<?php
session_start();

// Security Check: Only Tenants
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'tenant') {
    header("Location: ../login.php");
    exit;
}
?>
<!DOCTYPE html>
<html class="dark" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Payment Cancelled | Horizon</title>
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
    </style>
</head>
<body class="antialiased min-h-screen flex flex-col items-center justify-center p-6">

    <div class="max-w-md w-full glass-card p-10 text-center relative overflow-hidden">
        <div class="size-20 rounded-full bg-yellow-500/10 text-yellow-500 flex items-center justify-center mx-auto mb-6">
            <span class="material-symbols-outlined text-5xl">warning</span>
        </div>
        <h2 class="text-2xl font-black uppercase italic italic mb-2">Payment Cancelled</h2>
        <p class="text-gray-400 text-sm mb-8">You have cancelled the payment process. No charges were made to your account. You can retry anytime.</p>
        
        <div class="flex flex-col gap-3">
            <a href="subscription_plan.php" class="inline-flex items-center justify-center w-full h-12 rounded-xl bg-primary text-white text-[10px] font-black uppercase tracking-widest hover:brightness-110 transition-all">
                Choose a Plan
            </a>
            <a href="tenant_dashboard.php" class="text-gray-500 text-[10px] font-black uppercase tracking-widest hover:text-white transition-all">
                Back to Dashboard
            </a>
        </div>
    </div>

</body>
</html>
