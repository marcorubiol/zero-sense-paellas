<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Components;

use WC_Order;

class BricksCompatibility
{
    public function register(): void
    {
        add_filter('woocommerce_order_needs_payment', [$this, 'forcePaymentForDepositStatuses'], 10, 2);
        add_filter('woocommerce_valid_order_statuses_for_payment', [$this, 'extendValidPaymentStatuses']);
        add_action('template_redirect', [$this, 'handleOrderPayNotices'], 20);
    }

    public function forcePaymentForDepositStatuses($needsPayment, $order)
    {
        if (!$order instanceof WC_Order) {
            return $needsPayment;
        }

        if ($order->has_status(['budget-requested', 'deposit-paid', 'fully-paid'])) {
            return true;
        }

        return $needsPayment;
    }

    public function extendValidPaymentStatuses($statuses)
    {
        if (!is_array($statuses)) {
            $statuses = ['pending', 'failed'];
        }

        $statuses[] = 'budget-requested';
        $statuses[] = 'deposit-paid';
        $statuses[] = 'fully-paid';

        return array_values(array_unique($statuses));
    }

    public function handleOrderPayNotices(): void
    {
        if (!is_wc_endpoint_url('order-pay')) {
            return;
        }

        global $wp;
        $orderId = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        $order = $orderId ? wc_get_order($orderId) : null;
        if (!$order instanceof WC_Order) {
            return;
        }

        if (!$order->has_status(['deposit-paid', 'fully-paid'])) {
            return;
        }

        $notices = wc_get_notices();
        $removed = false;

        if (isset($notices['error']) && is_array($notices['error'])) {
            foreach ($notices['error'] as $key => $notice) {
                if (!is_string($notice)) {
                    continue;
                }

                if (stripos($notice, 'cannot be paid') !== false || stripos($notice, 'deposit paid') !== false) {
                    unset($notices['error'][$key]);
                    $removed = true;
                }
            }

            if (empty($notices['error'])) {
                unset($notices['error']);
            }
        }

        if ($removed) {
            WC()->session->set('wc_notices', $notices);
            wc_add_notice(__('Please continue with your payment below.', 'zero-sense'), 'notice');
        }
    }
}
