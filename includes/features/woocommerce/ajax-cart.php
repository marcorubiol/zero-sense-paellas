<?php
/**
 * WooCommerce AJAX Cart Functions & Inline Script Data
 * Handles AJAX cart operations and adds localized data inline for Bricks JS.
 */

if (!defined('ABSPATH')) exit; // Prevent direct access

// === Enqueue jQuery & Add Inline Localization Data ===

add_action('wp_enqueue_scripts', 'zs_enqueue_cart_scripts_and_data');

function zs_enqueue_cart_scripts_and_data() {
    // Load only on relevant pages
    if (is_woocommerce() || is_cart() || is_checkout() || is_shop() || is_product() || is_page('pedido')) { // Adjust page slug/ID if needed

        wp_enqueue_script('jquery');

        $script_data = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('zs_cart_nonce'),
            'i18n'    => [
                'added'      => __('Item added to cart', 'zero-sense'), // Replace 'zero-sense' with your text domain
                'removed'    => __('Item removed from cart', 'zero-sense'),
                'error'      => __('Error occurred', 'zero-sense'),
                'processing' => __('Processing...', 'zero-sense'),
                'empty_cart' => __('Tu carrito está vacío', 'zero-sense'),
            ]
        ];

        // Define zsCartHandler object inline, attached to jQuery
        $inline_script = sprintf("const zsCartHandler = %s;", wp_json_encode($script_data));
        wp_add_inline_script('jquery', $inline_script, 'before');
    }
}

// === AJAX Handlers ===

/**
 * AJAX add to cart handler
 */
function zs_add_to_cart() {
    check_ajax_referer('zs_cart_nonce', 'security');
    if (!isset($_POST['product_id'])) { wp_send_json_error(['message' => 'Missing product ID']); return; }
    $product_id = absint($_POST['product_id']);
    $quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 1;
    if (!function_exists('WC') || !isset(WC()->cart)) { wp_send_json_error(['message' => 'WooCommerce not available']); return; }
    if (isset(WC()->session) && !WC()->session->has_session()) { WC()->session->set_customer_session_cookie(true); }
    $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity);
    if ($cart_item_key) {
        if (isset(WC()->session)) { WC()->session->save_data(); }
        $fragments = apply_filters('woocommerce_add_to_cart_fragments', []);
        wp_send_json_success(['fragments' => $fragments, 'cart_hash' => WC()->cart->get_cart_hash(), 'cart_item_key' => $cart_item_key, 'message' => __('Product added to cart', 'zero-sense')]);
    } else { wp_send_json_error(['message' => __('Could not add product to cart', 'zero-sense')]); }
}
add_action('wp_ajax_zs_add_to_cart', 'zs_add_to_cart');
add_action('wp_ajax_nopriv_zs_add_to_cart', 'zs_add_to_cart');

/**
 * AJAX remove from cart handler
 */
function zs_remove_from_cart() {
    check_ajax_referer('zs_cart_nonce', 'security');
    if (!isset($_POST['cart_item_key'])) { wp_send_json_error(['message' => 'Missing cart item key']); return; }
    $cart_item_key = sanitize_text_field($_POST['cart_item_key']);
    if (!function_exists('WC') || !isset(WC()->cart)) { wp_send_json_error(['message' => 'WooCommerce not available']); return; }
    if (isset(WC()->session) && !WC()->session->has_session()) { WC()->session->set_customer_session_cookie(true); }
    $removed = WC()->cart->remove_cart_item($cart_item_key);
    if ($removed) {
        if (isset(WC()->session)) { WC()->session->save_data(); }
        $fragments = apply_filters('woocommerce_add_to_cart_fragments', []);
        wp_send_json_success(['fragments' => $fragments, 'cart_hash' => WC()->cart->get_cart_hash(), 'message' => __('Product removed from cart', 'zero-sense')]);
    } else { wp_send_json_error(['message' => __('Could not remove product from cart', 'zero-sense')]); }
}
add_action('wp_ajax_zs_remove_from_cart', 'zs_remove_from_cart');
add_action('wp_ajax_nopriv_zs_remove_from_cart', 'zs_remove_from_cart');

/**
 * AJAX update cart quantity handler
 */
function zs_update_cart_quantity() {
    check_ajax_referer('zs_cart_nonce', 'security');
    if (!isset($_POST['cart_item_key']) || !isset($_POST['quantity'])) { wp_send_json_error(['message' => 'Missing required parameters']); return; }
    $cart_item_key = sanitize_text_field($_POST['cart_item_key']);
    $quantity = absint($_POST['quantity']);
    if (!function_exists('WC') || !isset(WC()->cart)) { wp_send_json_error(['message' => 'WooCommerce not available']); return; }
    if (isset(WC()->session) && !WC()->session->has_session()) { WC()->session->set_customer_session_cookie(true); }
    $updated = WC()->cart->set_quantity($cart_item_key, $quantity);
    if ($updated) {
        if (isset(WC()->session)) { WC()->session->save_data(); }
        $fragments = apply_filters('woocommerce_add_to_cart_fragments', []);
        wp_send_json_success(['fragments' => $fragments, 'cart_hash' => WC()->cart->get_cart_hash(), 'cart_total' => WC()->cart->get_cart_total()]);
    } else { wp_send_json_error(['message' => __('Could not update quantity', 'zero-sense')]); }
}
add_action('wp_ajax_zs_update_quantity', 'zs_update_cart_quantity');
add_action('wp_ajax_nopriv_zs_update_quantity', 'zs_update_cart_quantity');

/**
 * AJAX get updated cart totals handler
 */
function zs_get_cart_totals() {
    check_ajax_referer('zs_cart_nonce', 'security');
    if (!function_exists('WC') || !isset(WC()->cart)) { wp_send_json_error(['message' => 'WooCommerce not available']); return; }
    WC()->cart->calculate_totals();
    wp_send_json_success(['subtotal' => WC()->cart->get_cart_subtotal(), 'total' => WC()->cart->get_cart_total()]);
}
add_action('wp_ajax_zs_get_cart_totals', 'zs_get_cart_totals');
add_action('wp_ajax_nopriv_zs_get_cart_totals', 'zs_get_cart_totals');

/**
 * AJAX get cart item key for a product handler
 */
function zs_get_cart_item_key() {
    check_ajax_referer('zs_cart_nonce', 'security');
    if (!isset($_POST['product_id'])) { wp_send_json_error(['message' => 'Missing product ID']); return; }
    $product_id = absint($_POST['product_id']);
    if (!function_exists('WC') || !isset(WC()->cart)) { wp_send_json_error(['message' => 'WooCommerce not available']); return; }
    $cart_item_key = '';
    foreach (WC()->cart->get_cart() as $key => $item) {
        if ($item['product_id'] == $product_id || (isset($item['variation_id']) && $item['variation_id'] == $product_id)) { $cart_item_key = $key; break; }
    }
    if ($cart_item_key) { wp_send_json_success(['cart_item_key' => $cart_item_key]); }
    else { wp_send_json_error(['message' => 'Product not in cart']); }
}
add_action('wp_ajax_zs_get_cart_item_key', 'zs_get_cart_item_key');
add_action('wp_ajax_nopriv_zs_get_cart_item_key', 'zs_get_cart_item_key');


// === Filters & Other Setup ===

/**
 * Add/Update cart button state via fragments
 */
function zs_add_cart_button_fragments($fragments) {
    if (!function_exists('WC') || !isset(WC()->cart)) { return $fragments; }
    $cart_items = WC()->cart->get_cart(); $in_cart_product_ids = [];
    foreach ($cart_items as $cart_item_key => $item) { $product_id_for_button = !empty($item['variation_id']) ? $item['variation_id'] : $item['product_id']; $in_cart_product_ids[$product_id_for_button] = $cart_item_key; }
    foreach ($in_cart_product_ids as $product_id => $cart_item_key) {
        $selector = ".cart-circle-btn[data-product-id='{$product_id}']";
        $svg_html = '<svg class="icon icon-plus" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg><svg class="icon icon-loading" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 4V2A10 10 0 0 0 2 12h2a8 8 0 0 1 8-8z" class="loading-path"/></svg><svg class="icon icon-check" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg><svg class="icon icon-remove" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg><span class="visually-hidden">Remove from cart</span>';
        $button_html = sprintf('<button type="button" class="cart-circle-btn in-cart zs-remove-from-cart" data-product-id="%d" data-cart-item-key="%s" data-quantity="1" title="%s" aria-label="%s" role="button" aria-live="polite">%s</button>', esc_attr($product_id), esc_attr($cart_item_key), esc_attr__('Remove from cart', 'zero-sense'), esc_attr__('Remove from cart', 'zero-sense'), $svg_html);
        $fragments[$selector] = $button_html;
    }
    return $fragments;
}
add_filter('woocommerce_add_to_cart_fragments', 'zs_add_cart_button_fragments');

/**
 * Extend WooCommerce session cookie lifetime
 */
function zs_extend_wc_cookie_lifetime() {
    add_filter('wc_session_expiring', function() { return 60 * 60 * 24; }); // 24 hours
    add_filter('wc_session_expiration', function() { return 60 * 60 * 48; }); // 48 hours
}
add_action('init', 'zs_extend_wc_cookie_lifetime', 5);

/**
 * Ensure cart session cookie is set for non-admin users
 */
function zs_ensure_cart_cookies() {
    if (!is_admin() && function_exists('WC') && isset(WC()->session) && !WC()->session->has_session()) {
        WC()->session->set_customer_session_cookie(true);
    }
}
add_action('woocommerce_init', 'zs_ensure_cart_cookies', 1); 