<?php
/**
 * Log Deletion Feature
 * 
 * Provides individual log deletion functionality across all Zero Sense log metaboxes:
 * - Calendar Logs
 * - Deposits Logs  
 * - Order Status Logs
 * - Email Logs (FlowMattic)
 * - Holded Logs (FlowMattic)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Register the feature
add_action('zero_sense_register_features', function() {
    zero_sense_register_feature('log-deletion', [
        'title' => __('Log Deletion', 'zero-sense'),
        'description' => __('Allows individual deletion of log entries across all metaboxes', 'zero-sense'),
        'category' => 'woocommerce',
        'always_on' => true,
        'toggleable' => false,
        'icon' => 'trash',
        'priority' => 80,
        'dependencies' => [],
        'files' => [
            'src/ZeroSense/Features/WooCommerce/LogDeletion.php',
            'assets/js/log-deletion.js',
            'assets/css/admin-components.css' // Modified for delete button styles
        ],
        'hooks' => [
            'admin_enqueue_scripts',
            'wp_ajax_zs_delete_log'
        ]
    ]);
});

// Initialize the feature
add_action('zero_sense_init_features', function() {
    if (zero_sense_is_feature_active('log-deletion')) {
        $logDeletion = new \ZeroSense\Features\WooCommerce\LogDeletion();
        $logDeletion->register();
    }
});
