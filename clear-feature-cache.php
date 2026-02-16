<?php
/**
 * Clear Feature Cache Script
 * Run this once to clear the feature cache and force rediscovery
 */

// Load WordPress
require_once(__DIR__ . '/../../../wp-load.php');

if (!defined('ABSPATH')) {
    die('WordPress not loaded');
}

// Clear the transient cache
$version = ZERO_SENSE_VERSION;
$cacheKey = 'zs_feature_classes_v' . $version;

$deleted = delete_transient($cacheKey);

if ($deleted) {
    echo "✅ Feature cache cleared successfully!\n";
    echo "Cache key: {$cacheKey}\n";
    echo "\nPlease refresh your WordPress admin to see the new menu.\n";
} else {
    echo "ℹ️  No cache found or already cleared.\n";
    echo "Cache key: {$cacheKey}\n";
}

echo "\nDone!\n";
