<?php
require_once 'db.php';

try {
    // Check if columns exist
    $stmt = $pdo->query("SHOW COLUMNS FROM tenant_pages LIKE 'font_family'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE tenant_pages ADD COLUMN font_family VARCHAR(50) DEFAULT 'Lexend'");
        echo "Added font_family column.\n";
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM tenant_pages LIKE 'button_shape'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE tenant_pages ADD COLUMN button_shape VARCHAR(50) DEFAULT 'rounded-2xl'");
        echo "Added button_shape column.\n";
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM tenant_pages LIKE 'theme_mode'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE tenant_pages ADD COLUMN theme_mode VARCHAR(20) DEFAULT 'dark'");
        echo "Added theme_mode column.\n";
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM tenant_pages LIKE 'font_size'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE tenant_pages ADD COLUMN font_size VARCHAR(20) DEFAULT 'base'");
        echo "Added font_size column.\n";
    }

    echo "Database migration complete.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
