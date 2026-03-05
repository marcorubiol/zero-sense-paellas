<?php
/**
 * One-time cleanup script for Migration Tools
 * Run this once and then delete this file
 */

// Load WordPress
require_once dirname(__FILE__) . '/../../../wp-load.php';

// Only allow admins
if (!current_user_can('manage_options')) {
    die('Access denied');
}

echo "<h1>Migration Tools Cleanup</h1>";

// Remove options
delete_option('zs_metabox_migration_enabled');
delete_option('zs_order_validation_enabled');
echo "<p>✓ Removed database options</p>";

// Clear feature cache
delete_transient('zs_feature_classes_cache');
echo "<p>✓ Cleared feature cache</p>";

// Update version marker
update_option('zs_plugin_version', '3.4.5');
echo "<p>✓ Updated plugin version to 3.4.5</p>";

echo "<h2>✅ Cleanup Complete!</h2>";
echo "<p><strong>Please delete this file (cleanup-migration-tools.php) now.</strong></p>";
echo "<p><a href='/wp-admin/'>Go to WordPress Admin</a></p>";
