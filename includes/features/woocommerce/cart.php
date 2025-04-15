<?php
/**
 * Zero Sense WooCommerce Cart Functions
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Empty the cart when specific URL parameter is present
 * 
 * Usage: Add ?zs_empty_cart=1 to any URL to create an "Empty Cart" link
 * Example: <a href="<?php echo add_query_arg('zs_empty_cart', '1', wc_get_cart_url()); ?>">Empty Cart</a>
 */
function zs_feature_empty_cart() {
    if (isset($_GET['zs_empty_cart'])) {
        global $woocommerce;
        $woocommerce->cart->empty_cart();
        wp_redirect(wc_get_cart_url());
        exit;
    }
}
add_action('init', 'zs_feature_empty_cart'); 