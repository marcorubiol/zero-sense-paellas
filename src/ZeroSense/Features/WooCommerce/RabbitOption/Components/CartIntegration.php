<?php
namespace ZeroSense\Features\WooCommerce\RabbitOption\Components;

use WC_Order_Item_Product;
use ZeroSense\Features\WooCommerce\RabbitOption\Support\MetaKeys;

class CartIntegration
{
    public function register(): void
    {
        // Store choice in cart item data
        add_filter('woocommerce_add_cart_item_data', [$this, 'addToCartData'], 10, 2);

        // Display choice in cart and checkout
        add_filter('woocommerce_get_item_data', [$this, 'displayInCart'], 10, 2);

        // Persist to order item meta
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'saveToOrderItem'], 10, 4);

        // AJAX endpoint to store rabbit choice in WC session (called by toggle JS)
        add_action('wp_ajax_zs_set_rabbit_choice', [$this, 'ajaxSetRabbitChoice']);
        add_action('wp_ajax_nopriv_zs_set_rabbit_choice', [$this, 'ajaxSetRabbitChoice']);

        // Reset rabbit choices when cart is emptied (new order starts)
        add_action('woocommerce_cart_emptied', [$this, 'resetRabbitChoices']);

        // Reset rabbit choices when order is completed
        add_action('woocommerce_thankyou', [$this, 'resetRabbitChoices']);
    }

    public function ajaxSetRabbitChoice(): void
    {
        $productId = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $choice    = isset($_POST['choice']) ? sanitize_text_field($_POST['choice']) : 'with';

        if (!in_array($choice, ['with', 'without'], true) || $productId <= 0) {
            wp_send_json_error();
            return;
        }

        if (function_exists('WC') && WC()->session) {
            $key = 'zs_rabbit_choice_' . $productId;
            WC()->session->set($key, $choice);
        }

        wp_send_json_success();
    }

    public function addToCartData(array $cartItemData, int $productId): array
    {
        if (!ProductDisplay::productHasRabbitOption($productId)) {
            return $cartItemData;
        }

        if (isset($cartItemData[MetaKeys::CART_KEY]) && in_array($cartItemData[MetaKeys::CART_KEY], ['with', 'without'], true)) {
            return $cartItemData;
        }

        $choice = 'with';
        if (function_exists('WC') && WC()->session) {
            $stored = WC()->session->get('zs_rabbit_choice_' . $productId);

            // WPML fallback: try canonical (default lang) ID if not found with current ID
            if (!in_array($stored, ['with', 'without'], true) && defined('ICL_SITEPRESS_VERSION')) {
                $defaultLang  = apply_filters('wpml_default_language', null);
                $canonicalId  = apply_filters('wpml_object_id', $productId, 'product', true, $defaultLang);
                if ($canonicalId && (int) $canonicalId !== $productId) {
                    $stored = WC()->session->get('zs_rabbit_choice_' . (int) $canonicalId);
                }
            }

            if (in_array($stored, ['with', 'without'], true)) {
                $choice = $stored;
            }
        }

        $cartItemData[MetaKeys::CART_KEY] = $choice;
        return $cartItemData;
    }

    public function displayInCart(array $itemData, array $cartItem): array
    {
        if (empty($cartItem[MetaKeys::CART_KEY])) {
            return $itemData;
        }

        $itemData[] = [
            'key'   => __('Rabbit', 'zero-sense'),
            'value' => $cartItem[MetaKeys::CART_KEY] === 'with'
                ? __('With rabbit', 'zero-sense')
                : __('Without rabbit', 'zero-sense'),
        ];

        return $itemData;
    }

    public function saveToOrderItem(WC_Order_Item_Product $item, string $cartItemKey, array $values, $order): void
    {
        if (!empty($values[MetaKeys::CART_KEY])) {
            $item->add_meta_data(MetaKeys::RABBIT_CHOICE, $values[MetaKeys::CART_KEY], true);
        }
    }

    /**
     * Reset all rabbit choices when cart is emptied (new order starts)
     */
    public function resetRabbitChoices(): void
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        // Get all session data and remove rabbit choice keys
        $sessionData = WC()->session->get_session_data();
        
        foreach ($sessionData as $key => $value) {
            if (strpos($key, 'zs_rabbit_choice_') === 0) {
                WC()->session->set($key, null); // Remove from session
            }
        }
    }
}
