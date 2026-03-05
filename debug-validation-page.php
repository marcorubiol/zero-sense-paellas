<?php
/**
 * Debug script to check OrderValidationPage status
 * Access via: https://paellasencasa.com/wp-content/plugins/zero-sense/debug-validation-page.php
 */

// Load WordPress
$wp_load_paths = [
    __DIR__ . '/../../../../wp-load.php',
    __DIR__ . '/../../../wp-load.php',
    dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php',
];

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        $wp_loaded = true;
        break;
    }
}

if (!$wp_loaded) {
    die('Error: Could not find wp-load.php. Tried paths: ' . implode(', ', $wp_load_paths));
}

if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    die('Access denied');
}

header('Content-Type: text/plain; charset=utf-8');

echo "=== ORDER VALIDATION PAGE DEBUG ===\n\n";

// 1. Check if file exists
$filePath = __DIR__ . '/src/ZeroSense/Features/WooCommerce/Migration/OrderValidationPage.php';
echo "1. File exists: " . (file_exists($filePath) ? 'YES ❌' : 'NO ✅') . "\n";
echo "   Path: {$filePath}\n\n";

// 2. Check if class exists
$classExists = class_exists('ZeroSense\\Features\\WooCommerce\\Migration\\OrderValidationPage');
echo "2. Class loaded: " . ($classExists ? 'YES ❌' : 'NO ✅') . "\n\n";

// 3. Check feature cache
$cacheKey = 'zs_feature_classes_v' . ZERO_SENSE_VERSION;
$cached = get_transient($cacheKey);
echo "3. Feature cache key: {$cacheKey}\n";
echo "   Cache exists: " . ($cached !== false ? 'YES' : 'NO') . "\n";
if ($cached !== false && is_array($cached)) {
    $hasValidation = in_array('ZeroSense\\Features\\WooCommerce\\Migration\\OrderValidationPage', $cached);
    echo "   Contains OrderValidationPage: " . ($hasValidation ? 'YES ❌' : 'NO ✅') . "\n";
    echo "   Total features cached: " . count($cached) . "\n";
}
echo "\n";

// 4. Check WordPress object cache
if (function_exists('wp_cache_get')) {
    $wpCache = wp_cache_get($cacheKey, 'default');
    echo "4. WP Object Cache: " . ($wpCache !== false ? 'EXISTS' : 'EMPTY') . "\n\n";
}

// 5. Check plugin version
echo "5. Plugin version: " . ZERO_SENSE_VERSION . "\n";
echo "   Version option: " . get_option('zs_plugin_version', 'not set') . "\n\n";

// 6. Check if option was cleaned
echo "6. Cleanup status:\n";
echo "   zs_order_validation_enabled: " . (get_option('zs_order_validation_enabled') !== false ? 'EXISTS ❌' : 'DELETED ✅') . "\n";
echo "   zs_metabox_migration_enabled: " . (get_option('zs_metabox_migration_enabled') !== false ? 'EXISTS ❌' : 'DELETED ✅') . "\n\n";

// 7. Check admin menu
global $submenu;
echo "7. WooCommerce submenu pages:\n";
if (isset($submenu['woocommerce'])) {
    foreach ($submenu['woocommerce'] as $item) {
        if (isset($item[2]) && strpos($item[2], 'validation') !== false) {
            echo "   FOUND: {$item[0]} -> {$item[2]} ❌\n";
        }
    }
    echo "   Total WC submenu items: " . count($submenu['woocommerce']) . "\n";
} else {
    echo "   WooCommerce menu not loaded yet\n";
}
echo "\n";

// 8. Recommendations
echo "=== RECOMMENDATIONS ===\n\n";

if (file_exists($filePath)) {
    echo "❌ FILE STILL EXISTS - Deploy failed or not executed\n";
    echo "   Action: Re-deploy or manually delete the file\n\n";
}

if ($cached !== false && is_array($cached)) {
    $hasValidation = in_array('ZeroSense\\Features\\WooCommerce\\Migration\\OrderValidationPage', $cached);
    if ($hasValidation) {
        echo "❌ FEATURE CACHE CONTAINS OrderValidationPage\n";
        echo "   Action: Clear cache with this command:\n";
        echo "   delete_transient('{$cacheKey}');\n";
        echo "   wp_cache_flush();\n\n";
        
        // Auto-clear option
        echo "   Auto-clearing now...\n";
        delete_transient($cacheKey);
        wp_cache_flush();
        echo "   ✅ Cache cleared!\n\n";
    }
}

if ($classExists) {
    echo "❌ CLASS IS LOADED IN MEMORY\n";
    echo "   Action: Restart PHP-FPM or clear OPcache\n\n";
}

echo "=== END DEBUG ===\n";
