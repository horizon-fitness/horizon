<!-- Shared Registration Form Fields -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="space-y-2">
        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">FIRST NAME</label>
        <input type="text" name="first_name" class="input-field" placeholder="John" required value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>">
    </div>
    <div class="space-y-2">
        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">MIDDLE NAME</label>
        <input type="text" name="middle_name" class="input-field" placeholder="Quincy" value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
    </div>
    <div class="space-y-2">
        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">LAST NAME</label>
        <input type="text" name="last_name" class="input-field" placeholder="Doe" required value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>">
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <div class="space-y-2">
        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">EMAIL ADDRESS</label>
        <input type="email" name="email" class="input-field" placeholder="member@example.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    </div>
    <div class="space-y-2">
        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">CONTACT NUMBER</label>
        <input type="text" name="phone_number" class="input-field" placeholder="09XX XXX XXXX" value="<?= htmlspecialchars($_POST['phone_number'] ?? '') ?>">
    </div>
</div>

<div class="space-y-2">
    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">HOME ADDRESS</label>
    <input type="text" name="address" class="input-field" placeholder="123 Street, Brgy, City" required value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <div class="space-y-2">
        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">BIRTH DATE</label>
        <input type="date" name="birth_date" class="input-field" required value="<?= htmlspecialchars($_POST['birth_date'] ?? '') ?>">
    </div>
    <div class="space-y-2">
        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">SEX</label>
        <select name="sex" class="input-field appearance-none">
            <option value="Male" <?= ($_POST['sex'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
            <option value="Female" <?= ($_POST['sex'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
            <option value="Other" <?= ($_POST['sex'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
        </select>
    </div>
</div>

<?php if (!($is_staff_led ?? false)): ?>
<div class="grid grid-cols-1 md:grid-cols-2 gap-6 p-6 bg-white/5 rounded-2xl border border-white/5">
    <div class="space-y-2">
        <label class="text-[10px] font-black uppercase tracking-widest text-primary ml-1">CHOOSE USERNAME</label>
        <input type="text" name="username" class="input-field border-primary/20" placeholder="johndoe123" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
    </div>
    <div class="space-y-2">
        <label class="text-[10px] font-black uppercase tracking-widest text-primary ml-1">CREATE PASSWORD</label>
        <input type="password" name="password" class="input-field border-primary/20" placeholder="••••••••" required>
    </div>
</div>
<?php endif; ?>

<div class="space-y-2">
    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">OCCUPATION</label>
    <input type="text" name="occupation" class="input-field" placeholder="Software Engineer" value="<?= htmlspecialchars($_POST['occupation'] ?? '') ?>">
</div>

<div class="space-y-2">
    <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">MEDICAL HISTORY / ALLERGIES</label>
    <textarea name="medical_history" class="input-field h-24" placeholder="Mention any medical conditions..."><?= htmlspecialchars($_POST['medical_history'] ?? '') ?></textarea>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-6 border-t border-white/5">
    <div class="space-y-2">
        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">EMERGENCY NAME</label>
        <input type="text" name="emergency_contact_name" class="input-field" placeholder="ICE Contact" required value="<?= htmlspecialchars($_POST['emergency_contact_name'] ?? '') ?>">
    </div>
    <div class="space-y-2">
        <label class="text-[10px] font-black uppercase tracking-widest text-gray-500 ml-1">EMERGENCY PHONE</label>
        <input type="text" name="emergency_contact_number" class="input-field" placeholder="09XX XXX XXXX" required value="<?= htmlspecialchars($_POST['emergency_contact_number'] ?? '') ?>">
    </div>
</div>
