<?php
require_once 'db.php';

// Check if user is logged in as tenant/admin (simple security)
session_start();
if (!isset($_SESSION['user_id']) || !in_array(strtolower($_SESSION['role'] ?? ''), ['tenant', 'admin', 'superadmin'])) {
    die("Unauthorized. Please log in first.");
}

try {
    $sql = "ALTER TABLE tenant_pages
            ADD COLUMN secondary_color VARCHAR(20) DEFAULT '#14121a',
            ADD COLUMN font_family VARCHAR(50) DEFAULT 'Lexend',
            ADD COLUMN base_font_size VARCHAR(20) DEFAULT '16px',
            ADD COLUMN border_radius VARCHAR(20) DEFAULT '24px',
            ADD COLUMN theme_mode ENUM('dark', 'light') DEFAULT 'dark',
            ADD COLUMN home_title TEXT,
            ADD COLUMN home_subtitle TEXT,
            ADD COLUMN footer_text TEXT,
            ADD COLUMN portal_tab_text VARCHAR(50) DEFAULT 'Gym Portal',
            ADD COLUMN home_section_visible TINYINT(1) DEFAULT 1,
            ADD COLUMN contact_section_visible TINYINT(1) DEFAULT 1,
            ADD COLUMN footer_visible TINYINT(1) DEFAULT 1";
    
    $pdo->exec($sql);
    echo "<h1>Migration Successful</h1>";
    echo "<p>Database table `tenant_pages` has been successfully updated with premium customization columns.</p>";
    echo "<a href='tenant/tenant_dashboard.php'>Back to Dashboard</a>";
} catch (PDOException $e) {
    // If columns already exist, we ignore the error
    if ($e->getCode() == '42S21') {
        echo "<h1>Migration Already Done</h1>";
        echo "<p>Columns already exist in the database.</p>";
        echo "<a href='tenant/tenant_dashboard.php'>Back to Dashboard</a>";
    } else {
        die("Migration failed: " . $e->getMessage());
    }
}
?>
