<header class="mb-10 flex flex-row justify-between items-end gap-6 no-print">
    <div>
        <h2 class="text-3xl font-black italic uppercase tracking-tighter text-white leading-none"><?= $header_title ?? 'Admin <span class="text-primary">(Developer)</span>' ?></h2>
        <p class="text-gray-500 text-xs font-bold uppercase tracking-widest mt-2"><?= $header_subtitle ?? 'Enterprise System Control Center' ?></p>
    </div>
    <div class="flex items-end gap-8">
        <?php if (isset($header_action)): ?>
            <div class="hidden md:block">
                <?= $header_action ?>
            </div>
        <?php endif; ?>
        <div class="text-right min-w-[120px]">
            <p id="headerClock" class="text-white font-black italic text-xl tracking-tight leading-none mb-2">00:00:00 AM</p>
            <p class="text-primary text-[9px] font-black uppercase tracking-[0.15em] opacity-80 whitespace-nowrap"><?= date('l, M d, Y') ?></p>
        </div>
    </div>
</header>
