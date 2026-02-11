<?php
namespace ZeroSense\Features\WooCommerce\OrderPay\Components;

use WC_Order;

class ShippingEmail
{
    private const META_KEY = '_shipping_email';

    public function __construct()
    {
        add_action('template_redirect', [$this, 'maybeRegister'], 1);
    }

    public function maybeRegister(): void
    {
        if (!$this->isOrderPayPage()) {
            return;
        }

        add_action('woocommerce_review_order_before_submit', [$this, 'renderField'], 8);
        add_action('woocommerce_pay_order_before_submit', [$this, 'renderField'], 8);

        add_action('woocommerce_checkout_update_order_meta', [$this, 'save'], 20, 1);
    }

    public function renderField(): void
    {
        $order = $this->getOrderFromRequest();
        $value = '';

        if ($order instanceof WC_Order) {
            $raw = $order->get_meta(self::META_KEY, true);
            $value = is_string($raw) ? $raw : '';
        }

        woocommerce_form_field('shipping_email', [
            'type' => 'email',
            'class' => ['form-row-wide'],
            'label' => __('On-site contact email', 'zero-sense'),
            'required' => false,
        ], $value);
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
            $order->save();
            return;
        }
    }

    private function isOrderPayPage(): bool
    {
        return (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) ||
            (isset($_GET['pay_for_order'], $_GET['key']));
    }

    private function getOrderFromRequest(): ?WC_Order
    {
        if (function_exists('is_checkout_pay_page') && is_checkout_pay_page()) {
            if (isset($_GET['key']) && is_string($_GET['key']) && function_exists('wc_get_order_id_by_order_key')) {
                $id = absint(wc_get_order_id_by_order_key(wp_unslash($_GET['key'])));
                if ($id > 0) {
                    $order = wc_get_order($id);
                    return $order instanceof WC_Order ? $order : null;
                }
            }

            global $wp;
            if (isset($wp->query_vars['order-pay'])) {
                $id = absint($wp->query_vars['order-pay']);
                $order = wc_get_order($id);
                return $order instanceof WC_Order ? $order : null;
            }
        }

        return null;
    }
}
