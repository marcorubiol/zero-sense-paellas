<?php
/**
 * Zero Sense Features Initialization
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// WordPress core features
require_once ZERO_SENSE_PATH . 'includes/features/wordpress/core.php';
require_once ZERO_SENSE_PATH . 'includes/features/wordpress/security.php';

// Load WooCommerce features if WooCommerce is active
if (class_exists('WooCommerce')) {
    require_once ZERO_SENSE_PATH . 'includes/woocommerce.php';
    require_once ZERO_SENSE_PATH . 'includes/features/woocommerce/cart.php';
    require_once ZERO_SENSE_PATH . 'includes/features/woocommerce/ajax-cart.php';
    require_once ZERO_SENSE_PATH . 'includes/features/woocommerce/tab-detection.php';
    require_once ZERO_SENSE_PATH . 'includes/features/woocommerce/text-modifications.php';
    require_once ZERO_SENSE_PATH . 'includes/features/woocommerce/checkout-meta-boxes.php';
    require_once ZERO_SENSE_PATH . 'includes/features/woocommerce/marketing-consent.php';
    // Add more WooCommerce feature files here
}

// Load Bricks Builder features if it's active
if (defined('BRICKS_VERSION')) {
    require_once ZERO_SENSE_PATH . 'includes/features/bricks/functions.php';
}

// Load other feature categories
// require_once ZERO_SENSE_PATH . 'includes/features/seo/init.php';
// require_once ZERO_SENSE_PATH . 'includes/features/performance/init.php';
// etc. 