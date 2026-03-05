<?php
/**
 * Check if Migration directory still exists
 * Access: https://paellasencasa.com/wp-content/plugins/zero-sense/check-migration-dir.php
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== MIGRATION DIRECTORY CHECK ===\n\n";

$migrationDir = __DIR__ . '/src/ZeroSense/Features/WooCommerce/Migration';

if (!is_dir($migrationDir)) {
    echo "✅ Migration directory DELETED - Good!\n";
    echo "The error should not appear anymore.\n\n";
    echo "If you still see the error:\n";
    echo "1. Clear browser cache (Ctrl+Shift+R)\n";
    echo "2. Clear OPcache (wait 2-3 minutes)\n";
    echo "3. Clear WordPress object cache\n";
} else {
    echo "❌ Migration directory STILL EXISTS\n";
    echo "Path: {$migrationDir}\n\n";
    
    $files = scandir($migrationDir);
    $phpFiles = array_filter($files, function($f) { return $f !== '.' && $f !== '..'; });
    
    echo "Files found:\n";
    foreach ($phpFiles as $file) {
        echo "  - {$file}\n";
    }
    
    echo "\n=== ACTION REQUIRED ===\n";
    echo "Run the emergency cleanup script:\n";
    echo "https://paellasencasa.com/wp-content/plugins/zero-sense/emergency-cleanup.php\n";
}

echo "\n=== END ===\n";
