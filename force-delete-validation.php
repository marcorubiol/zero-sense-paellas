<?php
/**
 * Force delete OrderValidationPage.php and clear all caches
 * Access via: https://paellasencasa.com/wp-content/plugins/zero-sense/force-delete-validation.php
 */

header('Content-Type: text/plain; charset=utf-8');

echo "=== FORCE DELETE ORDER VALIDATION PAGE ===\n\n";

// 1. Delete the file
$filePath = __DIR__ . '/src/ZeroSense/Features/WooCommerce/Migration/OrderValidationPage.php';
$dirPath = dirname($filePath);

if (file_exists($filePath)) {
    echo "1. Deleting file...\n";
    echo "   Path: {$filePath}\n";
    
    if (unlink($filePath)) {
        echo "   ✅ File deleted successfully!\n\n";
    } else {
        echo "   ❌ Failed to delete file (permission issue?)\n\n";
        die("ERROR: Could not delete file. Check file permissions.\n");
    }
} else {
    echo "1. File already deleted ✅\n\n";
}

// 2. Check if Migration directory is empty and delete it
if (is_dir($dirPath)) {
    $files = array_diff(scandir($dirPath), ['.', '..']);
    if (empty($files)) {
        echo "2. Migration directory is empty, removing it...\n";
        if (rmdir($dirPath)) {
            echo "   ✅ Directory removed!\n\n";
        } else {
            echo "   ⚠️  Could not remove directory\n\n";
        }
    } else {
        echo "2. Migration directory still has files: " . implode(', ', $files) . "\n\n";
    }
} else {
    echo "2. Migration directory already removed ✅\n\n";
}

// 3. Clear OPcache if available
echo "3. Clearing OPcache...\n";
if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        echo "   ✅ OPcache cleared!\n\n";
    } else {
        echo "   ⚠️  OPcache reset failed\n\n";
    }
} else {
    echo "   ⚠️  OPcache not available or not enabled\n\n";
}

// 4. Load WordPress and clear feature cache
$wp_load_paths = [
    __DIR__ . '/../../../../wp-load.php',
    __DIR__ . '/../../../wp-load.php',
];

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        $wp_loaded = true;
        break;
    }
}

if ($wp_loaded) {
    echo "4. Clearing WordPress caches...\n";
    
    // Clear feature cache
    $cacheKey = 'zs_feature_classes_v3.4.5';
    delete_transient($cacheKey);
    echo "   ✅ Feature cache cleared\n";
    
    // Clear object cache
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
        echo "   ✅ Object cache flushed\n";
    }
    
    echo "\n";
} else {
    echo "4. ⚠️  Could not load WordPress to clear caches\n\n";
}

echo "=== COMPLETED ===\n\n";
echo "Next steps:\n";
echo "1. Refresh the page: https://paellasencasa.com/wp-admin/admin.php?page=zs_order_validation\n";
echo "2. You should now get a 404 or 'page not found' error\n";
echo "3. Delete this cleanup script for security\n\n";

echo "If the page still appears:\n";
echo "- Clear your browser cache (Ctrl+Shift+R or Cmd+Shift+R)\n";
echo "- Wait 1-2 minutes for server cache to expire\n";
echo "- Contact hosting support to clear server-level cache\n\n";
