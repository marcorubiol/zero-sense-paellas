<?php
namespace ZeroSense\Features\WooCommerce\Checkout\Components;

use WC_Order;

class ShippingEmail
{
    private const META_KEY = '_shipping_email';

    public function __construct()
    {
        add_filter('woocommerce_checkout_fields', [$this, 'addField'], 30);
        add_action('woocommerce_checkout_create_order', [$this, 'save'], 20, 2);
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

    public function save(WC_Order $order, $data): void
    {
        if (!isset($_POST['shipping_email'])) {
            return;
        }

        $email = sanitize_email(wp_unslash((string) $_POST['shipping_email']));

        $order->update_meta_data(self::META_KEY, $email);
        $order->save();
    }
}
