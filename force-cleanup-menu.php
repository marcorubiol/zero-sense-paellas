<?php
/**
 * Force cleanup WordPress admin menu cache
 * Run this once and then delete this file
 */

// Load WordPress
require_once dirname(__FILE__) . '/../../../wp-load.php';

// Only allow admins
if (!current_user_can('manage_options')) {
    die('Access denied');
}

echo "<h1>Force Menu Cleanup</h1>";

// Clear all WordPress admin menu caches
global $wpdb;

// 1. Delete all transients related to admin menus
$deleted = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_%admin%' OR option_name LIKE '%_transient_%menu%'");
echo "<p>✓ Deleted {$deleted} admin/menu transients</p>";

// 2. Clear object cache
wp_cache_flush();
echo "<p>✓ Flushed object cache</p>";

// 3. Clear feature cache again
delete_transient('zs_feature_classes_cache');
echo "<p>✓ Cleared feature cache</p>";

// 4. Force user meta refresh (menu positions)
$current_user_id = get_current_user_id();
delete_user_meta($current_user_id, 'managenav-menuscolumnshidden');
delete_user_meta($current_user_id, 'closedpostboxes_nav-menus');
delete_user_meta($current_user_id, 'metaboxhidden_nav-menus');
echo "<p>✓ Cleared user menu meta for current user</p>";

// 5. Show current admin menu structure
echo "<h2>Current Admin Menus:</h2>";
echo "<ul>";
foreach ($GLOBALS['menu'] as $menu_item) {
    if (!empty($menu_item[2])) {
        echo "<li><code>{$menu_item[2]}</code> - {$menu_item[0]}</li>";
    }
}
echo "</ul>";

echo "<h2>✅ Force Cleanup Complete!</h2>";
echo "<p><strong>Now:</strong></p>";
echo "<ol>";
echo "<li>Delete this file (force-cleanup-menu.php)</li>";
echo "<li>Clear your browser cache (Cmd+Shift+R)</li>";
echo "<li>Logout and login again to WordPress</li>";
echo "<li>The menu should be gone!</li>";
echo "</ol>";
echo "<p><a href='/wp-admin/'>Go to WordPress Admin</a></p>";
