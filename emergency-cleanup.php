<?php
/**
 * Emergency cleanup script - removes Migration directory completely
 * Access: https://paellasencasa.com/wp-content/plugins/zero-sense/emergency-cleanup.php
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== EMERGENCY CLEANUP - MIGRATION DIRECTORY ===\n\n";

$migrationDir = __DIR__ . '/src/ZeroSense/Features/WooCommerce/Migration';

if (!is_dir($migrationDir)) {
    echo "✅ Migration directory doesn't exist - already clean!\n";
    exit;
}

echo "Found Migration directory: {$migrationDir}\n\n";

// List files before deletion
$files = scandir($migrationDir);
$phpFiles = array_filter($files, function($f) { return $f !== '.' && $f !== '..'; });

echo "Files to delete:\n";
foreach ($phpFiles as $file) {
    echo "  - {$file}\n";
}
echo "\n";

// Delete all files
$deletedCount = 0;
foreach ($phpFiles as $file) {
    $filePath = $migrationDir . '/' . $file;
    if (is_file($filePath)) {
        if (unlink($filePath)) {
            echo "✅ Deleted: {$file}\n";
            $deletedCount++;
        } else {
            echo "❌ Failed to delete: {$file}\n";
        }
    }
}

echo "\n";

// Remove directory
if (rmdir($migrationDir)) {
    echo "✅ Migration directory removed successfully!\n";
} else {
    echo "❌ Failed to remove Migration directory\n";
    echo "   (may still contain files)\n";
}

echo "\n=== CLEANUP COMPLETED ===\n";
echo "Deleted {$deletedCount} files\n\n";

echo "Next steps:\n";
echo "1. Refresh https://paellasencasa.com/wp-admin/admin.php?page=zs-calendar-bulk-sync\n";
echo "2. The error should be gone\n";
echo "3. Delete this cleanup script for security\n";
