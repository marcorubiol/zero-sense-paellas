<?php
namespace ZeroSense\Features\WooCommerce\RabbitOption\Components;

use WC_Order_Item_Product;
use ZeroSense\Features\WooCommerce\RabbitOption\Support\MetaKeys;

class CartIntegration
{
    public function register(): void
    {
        // Store choice in cart item data when adding to cart
        add_filter('woocommerce_add_cart_item_data', [$this, 'addToCartData'], 10, 2);

        // Restore choice from session when cart is loaded
        add_filter('woocommerce_get_cart_item_from_session', [$this, 'restoreFromSession'], 10, 2);

        // Display choice in cart and checkout
        add_filter('woocommerce_get_item_data', [$this, 'displayInCart'], 10, 2);

        // Persist to order item meta
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'saveToOrderItem'], 10, 4);

        // AJAX endpoint to store rabbit choice in WC session (called by toggle JS)
        add_action('wp_ajax_zs_set_rabbit_choice', [$this, 'ajaxSetRabbitChoice']);
        add_action('wp_ajax_nopriv_zs_set_rabbit_choice', [$this, 'ajaxSetRabbitChoice']);

        // Clear rabbit session keys when cart is emptied or order completes
        add_action('woocommerce_cart_emptied', [$this, 'clearRabbitSession']);
        add_action('woocommerce_thankyou', [$this, 'clearRabbitSession']);
    }

    public function ajaxSetRabbitChoice(): void
    {
        $productId = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $choice    = isset($_POST['choice']) ? sanitize_text_field($_POST['choice']) : 'with';

        if (!in_array($choice, ['with', 'without'], true) || $productId <= 0) {
            wp_send_json_error(['reason' => 'invalid_params']);
            return;
        }

        if (function_exists('WC')) {
            if (!WC()->session) {
                WC()->initialize_session();
            }
            if (WC()->session) {
                $key = 'zs_rabbit_choice_' . $productId;
                WC()->session->set($key, $choice);
                WC()->session->save_data();
            }
        }

        wp_send_json_success();
    }

    public function restoreFromSession(array $cartItem, array $values): array
    {
        if (isset($values[MetaKeys::CART_KEY]) && in_array($values[MetaKeys::CART_KEY], ['with', 'without'], true)) {
            $cartItem[MetaKeys::CART_KEY] = $values[MetaKeys::CART_KEY];
        }
        return $cartItem;
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

        // 1. Primary: read from POST (injected as hidden field by toggle JS)
        if (isset($_POST['zs_rabbit_choice']) && in_array($_POST['zs_rabbit_choice'], ['with', 'without'], true)) {
            $choice = $_POST['zs_rabbit_choice'];
        } elseif (function_exists('WC') && WC()->session) {
            // 2. Fallback: session (set by AJAX)
            $stored = WC()->session->get('zs_rabbit_choice_' . $productId);

            // WPML fallback: try canonical (default lang) ID if not found with current ID
            if (!in_array($stored, ['with', 'without'], true) && defined('ICL_SITEPRESS_VERSION')) {
                $defaultLang = apply_filters('wpml_default_language', null);
                $canonicalId = apply_filters('wpml_object_id', $productId, 'product', true, $defaultLang);
                if ($canonicalId && (int) $canonicalId !== $productId) {
                    $stored = WC()->session->get('zs_rabbit_choice_' . (int) $canonicalId);
                }
            }

            if (in_array($stored, ['with', 'without'], true)) {
                $choice = $stored;
            }

            // Consume session key so it doesn't bleed into future add-to-cart calls
            WC()->session->set('zs_rabbit_choice_' . $productId, null);
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

    public function clearRabbitSession(): void
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }
        $sessionData = WC()->session->get_session_data();
        foreach (array_keys($sessionData) as $key) {
            if (strpos($key, 'zs_rabbit_choice_') === 0) {
                WC()->session->set($key, null);
            }
        }
    }

    public function saveToOrderItem(WC_Order_Item_Product $item, string $cartItemKey, array $values, $order): void
    {
        if (!empty($values[MetaKeys::CART_KEY])) {
            $item->add_meta_data(MetaKeys::RABBIT_CHOICE, $values[MetaKeys::CART_KEY], true);
        }
    }
}
