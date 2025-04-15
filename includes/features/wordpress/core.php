<?php
/**
 * WordPress Core customizations
 *
 * @package ZeroSense
 */

defined('ABSPATH') || exit;

/**
 * Disable WS Form styler
 */
add_filter('wsf_styler_enabled', function() { 
    return false; 
});

/**
 * Add viewport meta tag to head
 */
function add_viewport_meta_tag() {
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">';
}
add_action('wp_head', 'add_viewport_meta_tag', 1);

/**
 * Hide admin bar for non-administrators on frontend
 */
add_action('after_setup_theme', function() {
    // Check if the setting is enabled (default to true if setting doesn't exist)
    $hide_admin_bar = get_option('zs_hide_admin_bar_non_admins', true);
    
    if ($hide_admin_bar && !current_user_can('administrator') && !is_admin()) {
        show_admin_bar(false);
    }
});

/**
 * Customize excerpt length
 * 
 * @param int $length Default excerpt length
 * @return int Custom excerpt length
 */
function custom_excerpt_length($length) {
    return 20; // number of words. Default is 55
}
add_filter('excerpt_length', 'custom_excerpt_length', 999);

/**
 * Enable featured images and add custom image sizes
 */
add_action('after_setup_theme', function() {
    // Make sure featured images are enabled
    add_theme_support('post-thumbnails');
    
    // Add featured image sizes
    add_image_size('image-480', 480, 999); // width, height, crop
    add_image_size('image-640', 640, 999); 
    add_image_size('image-720', 720, 999); 
    add_image_size('image-960', 960, 999); 
    add_image_size('image-1168', 1168, 999); 
    add_image_size('image-1440', 1440, 999); 
    add_image_size('image-1920', 1920, 999);
});

/**
 * Register image sizes for use in Add Media modal
 */
function media_custom_sizes($sizes) {
    return array_merge($sizes, array(
        'image-480' => __('image-480', 'zero-sense'),
        'image-640' => __('image-640', 'zero-sense'),
        'image-720' => __('image-720', 'zero-sense'),
        'image-960' => __('image-960', 'zero-sense'),
        'image-1168' => __('image-1168', 'zero-sense'),
        'image-1440' => __('image-1440', 'zero-sense'),
        'image-1920' => __('image-1920', 'zero-sense'),
    ));
}
add_filter('image_size_names_choose', 'media_custom_sizes');

/**
 * Add security headers
 */
add_action('send_headers', function() {
    // Basic Security Headers
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("Permissions-Policy: geolocation=(), microphone=(), camera=(), fullscreen=(), payment=()");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Expect-CT: max-age=86400, enforce");

    // Content Security Policy - CORRECTED to allow required sources
    $csp_value = "default-src 'self'; " .
                 // Allow scripts from self, inline, eval (use cautiously), specific CDNs, Google, and allow data: and blob: for workers
                 "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.paellasencasa.com https://optimizerwpc.b-cdn.net https://www.googletagmanager.com https://www.google-analytics.com data: blob:; " .
                 // Define worker-src explicitly for better security than fallback to script-src
                 "worker-src 'self' data: blob:; " .
                 "style-src 'self' 'unsafe-inline' https://cdn.paellasencasa.com; " .
                 // Allow images from self, data URIs, your CDN, your domain, Gravatar, and WooCommerce assets
                 "img-src 'self' data: https://cdn.paellasencasa.com https://paellasencasa.com https://secure.gravatar.com https://*.wp.com; " .
                 // Allow fonts from self, your CDN, and data URIs
                 "font-src 'self' data: https://cdn.paellasencasa.com; " .
                 "object-src 'none'; " .
                 "base-uri 'self'; " .
                 // Allow form submission to Redsys payment gateway
                 "form-action 'self' https://sis-t.redsys.es:25443 https://sis.redsys.es; " .
                 "frame-ancestors 'self';";

    header("Content-Security-Policy: " . $csp_value);
});

/**
 * Load Automatic CSS variables in admin pages
 */
function enqueue_acss_style_admin() {
    wp_register_style('automaticcss-variables', content_url() . '/uploads/automatic-css/automatic-variables.css');
    wp_enqueue_style('automaticcss-variables');
}
add_action('admin_enqueue_scripts', 'enqueue_acss_style_admin');

/**
 * Load Automatic CSS variables in login page
 */
function enqueue_acss_style_login() {
    wp_register_style('automaticcss-variables', content_url() . '/uploads/automatic-css/automatic-variables.css');
    wp_enqueue_style('automaticcss-variables');
}
add_action('login_enqueue_scripts', 'enqueue_acss_style_login'); 