<?php
/**
 * WP Debug JavaScript Helper
 * 
 * Exposes WP_DEBUG constant to JavaScript for conditional logging
 */

// Only load if WP_DEBUG is defined
if (!defined('WP_DEBUG')) {
    return;
}

// Add inline script to expose WP_DEBUG to JavaScript
add_action('wp_enqueue_scripts', function() {
    $debug_enabled = WP_DEBUG;
    
    $script = "
        window.wp_debug = " . ($debug_enabled ? 'true' : 'false') . ";
    ";
    
    wp_add_inline_script('jquery', $script);
});

// Also load in admin
add_action('admin_enqueue_scripts', function() {
    $debug_enabled = WP_DEBUG;
    
    $script = "
        window.wp_debug = " . ($debug_enabled ? 'true' : 'false') . ";
    ";
    
    wp_add_inline_script('jquery', $script);
});
