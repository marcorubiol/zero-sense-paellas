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
    }

    public function addToCartData(array $cartItemData, int $productId): array
    {
        if (!ProductDisplay::productHasRabbitOption($productId)) {
            return $cartItemData;
        }

        error_log('[ZS RabbitOption] addToCartData POST: ' . print_r($_POST, true));

        $choice = isset($_POST['zs_rabbit_choice']) ? sanitize_text_field($_POST['zs_rabbit_choice']) : 'with';
        if (!in_array($choice, ['with', 'without'], true)) {
            $choice = 'with';
        }

        error_log('[ZS RabbitOption] addToCartData resolved choice: ' . $choice);

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
        error_log('[ZS RabbitOption] saveToOrderItem values: ' . print_r($values, true));
        if (!empty($values[MetaKeys::CART_KEY])) {
            $item->add_meta_data(MetaKeys::RABBIT_CHOICE, $values[MetaKeys::CART_KEY], true);
            error_log('[ZS RabbitOption] saveToOrderItem saved: ' . $values[MetaKeys::CART_KEY]);
        } else {
            error_log('[ZS RabbitOption] saveToOrderItem: CART_KEY missing from values');
        }
    }
}
