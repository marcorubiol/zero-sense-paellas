<?php
/**
 * WordPress Security Features
 *
 * @package ZeroSense
 */

defined('ABSPATH') || exit;

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