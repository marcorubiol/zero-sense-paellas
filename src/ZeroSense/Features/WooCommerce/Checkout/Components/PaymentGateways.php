<?php
namespace ZeroSense\Features\WooCommerce\Checkout\Components;

class PaymentGateways
{
    public function __construct()
    {
        // Run very late so nothing overrides our decision
        add_filter('woocommerce_available_payment_gateways', [$this, 'filter_checkout_payment_gateways'], 9999);
    }

    /**
     * Filter available payment gateways on checkout and order-pay pages.
     * Only show 'Pay Later' on checkout (not order-pay).
     * Hide 'Pay Later' on order-pay.
     */
    public function filter_checkout_payment_gateways(array $available_gateways): array
    {
        $pay_later_gateway_id = 'pay_later';

        $isOrderPay = (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay'))
            || isset($_GET['pay_for_order']);
        $isCheckout = function_exists('is_checkout') && is_checkout();

        $debugIn  = array_keys($available_gateways);
        $debugBranch = $isOrderPay ? 'order-pay' : ($isCheckout ? 'checkout' : 'other');

        // Log ALL registered gateways and their enabled status
        $allGatewaysDebug = [];
        if (function_exists('WC') && WC()->payment_gateways) {
            foreach (WC()->payment_gateways->payment_gateways() as $gid => $gw) {
                $allGatewaysDebug[$gid] = [
                    'enabled' => $gw->enabled,
                    'available' => $gw->is_available(),
                ];
            }
        }

        // Check if on the order-pay page
        if ($isOrderPay) {
            // Hide Pay Later on order-pay
            if (isset($available_gateways[$pay_later_gateway_id])) {
                unset($available_gateways[$pay_later_gateway_id]);
            }
        }
        // Check if on the main checkout page (and not order-pay)
        elseif ($isCheckout && !$isOrderPay) {
            // Keep only Pay Later on checkout
            if (isset($available_gateways[$pay_later_gateway_id])) {
                $pay_later_gateway = $available_gateways[$pay_later_gateway_id];
                $available_gateways = [];
                $available_gateways[$pay_later_gateway_id] = $pay_later_gateway;
            } else {
                $available_gateways = [];
                if (function_exists('wc_get_logger')) {
                    wc_get_logger()->warning(
                        sprintf('Payment Gateway Filtering: Expected gateway "%s" not found or inactive on checkout page.', esc_html($pay_later_gateway_id)),
                        ['source' => 'zero-sense-checkout-filters']
                    );
                }
            }
        }

        $debugOut = array_keys($available_gateways);
        add_action('wp_footer', function() use ($debugIn, $debugOut, $debugBranch, $isOrderPay, $isCheckout, $allGatewaysDebug) {
            echo '<script>console.group("[ZS PaymentGateways]");'
                . 'console.log("branch:", ' . wp_json_encode($debugBranch) . ');'
                . 'console.log("isOrderPay:", ' . wp_json_encode($isOrderPay) . ');'
                . 'console.log("isCheckout:", ' . wp_json_encode($isCheckout) . ');'
                . 'console.log("gateways IN:", ' . wp_json_encode($debugIn) . ');'
                . 'console.log("gateways OUT:", ' . wp_json_encode($debugOut) . ');'
                . 'console.log("ALL gateways (enabled/available):", ' . wp_json_encode($allGatewaysDebug) . ');'
                . 'console.groupEnd();</script>';
        }, 999);

        return $available_gateways;
    }
}

