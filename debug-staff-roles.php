<?php
/**
 * Debug script to check and create staff roles
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Load WordPress
require_once('../../../wp-config.php');

// Check if Staff feature is loaded
if (class_exists('\ZeroSense\Features\Operations\Staff')) {
    echo "Staff feature class exists<br>";
    
    // Get instance of Staff feature
    $staff = new \ZeroSense\Features\Operations\Staff();
    
    // Call ensureCoreRoles directly
    echo "Calling ensureCoreRoles()...<br>";
    $staff->ensureCoreRoles();
    echo "ensureCoreRoles() completed<br>";
    
    // Check if roles exist
    $roles = get_terms([
        'taxonomy' => 'zs_staff_role',
        'hide_empty' => false,
    ]);
    
    echo "<br>Current staff roles:<br>";
    if ($roles && !is_wp_error($roles)) {
        foreach ($roles as $role) {
            echo "- {$role->name} (slug: {$role->slug})<br>";
        }
    } else {
        echo "No roles found or error: " . ($roles ? $roles->get_error_message() : 'Unknown') . "<br>";
    }
} else {
    echo "Staff feature class NOT found<br>";
}

// Check if Event Operations menu exists
if (class_exists('\ZeroSense\Features\Operations\EventOperationsMenu')) {
    echo "<br>EventOperationsMenu feature exists<br>";
} else {
    echo "<br>EventOperationsMenu feature NOT found<br>";
}

// Check all registered features
echo "<br>All discovered features:<br>";
$plugin = \ZeroSense\Core\Plugin::getInstance();
$featureManager = $plugin->getFeatureManager();
$features = $featureManager->getFeatures();

foreach ($features as $feature) {
    echo "- " . get_class($feature) . " (" . $feature->getName() . ")<br>";
}
