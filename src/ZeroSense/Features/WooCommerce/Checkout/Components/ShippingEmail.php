<?php
namespace ZeroSense\Features\WooCommerce\Checkout\Components;

use WC_Order;

class ShippingEmail
{
    private const META_KEY = 'zs_shipping_email';
    private const META_KEY_WOO = '_shipping_email';

    public function __construct()
    {
        add_filter('woocommerce_checkout_fields', [$this, 'addField'], 30);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save'], 20, 1);
    }

    public function addField($fields)
    {
        if (!isset($fields['shipping']) || !is_array($fields['shipping'])) {
            $fields['shipping'] = [];
        }

        $fields['shipping']['shipping_email'] = [
            'type' => 'email',
            'label' => __('On-site contact email', 'zero-sense'),
            'required' => false,
            'class' => ['form-row-wide'],
            'priority' => 115,
            'clear' => true,
        ];

        return $fields;
    }

    public function save($orderId): void
    {
        if (!$orderId || !isset($_POST['shipping_email'])) {
            return;
        }

        $email = sanitize_email(wp_unslash((string) $_POST['shipping_email']));

        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order) {
            $order->update_meta_data(self::META_KEY, $email);
            $order->update_meta_data(self::META_KEY_WOO, $email);
            $order->save();
            return;
        }

        update_post_meta($orderId, self::META_KEY, $email);
        update_post_meta($orderId, self::META_KEY_WOO, $email);
    }
}
