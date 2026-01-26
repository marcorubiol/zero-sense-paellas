<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Components;

use WC_Order;

class DirectPaymentHandler
{
    public function register(): void
    {
        add_action('wp', [$this, 'processPayment'], 10);
    }

    public function processPayment(): void
    {
        global $wp;

        if (empty($_POST['woocommerce_pay']) || empty($_POST['woocommerce-pay-nonce']) ||
            !wp_verify_nonce($_POST['woocommerce-pay-nonce'], 'woocommerce-pay')) {
            return;
        }

        if (empty($wp->query_vars['order-pay']) || empty($_GET['key'])) {
            return;
        }

        $orderId = absint($wp->query_vars['order-pay']);
        $orderKey = $_GET['key'];
        $order = wc_get_order($orderId);

        if (!$order instanceof WC_Order || $order->get_order_key() !== $orderKey) {
            return;
        }

        if (!$order->has_status('deposit-paid')) {
            return;
        }

        $paymentMethod = isset($_POST['payment_method']) ? wc_clean(wp_unslash($_POST['payment_method'])) : '';
        if (empty($paymentMethod)) {
            return;
        }

        $availableGateways = WC()->payment_gateways->get_available_payment_gateways();
        if (!isset($availableGateways[$paymentMethod])) {
            return;
        }

        $gateway = $availableGateways[$paymentMethod];
        
        // Update payment method BEFORE processing (critical for redirects and hooks)
        $currentMethod = $order->get_payment_method();
        if ($currentMethod !== $paymentMethod) {
            $order->set_payment_method($gateway);
            $order->save();
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    "[PAYMENT METHOD UPDATE] Order #%d | Changed from: %s → %s",
                    $orderId,
                    $currentMethod,
                    $paymentMethod
                ));
            }
        }

        $result = $gateway->process_payment($orderId);

        if (!empty($result['result']) && 'success' === $result['result'] && isset($result['redirect'])) {
            // Apply WooCommerce filter to allow redirect customization (e.g., for BACS deposit-paid orders)
            $result = apply_filters('woocommerce_payment_successful_result', $result, $orderId);
            
            wp_redirect($result['redirect']);
            exit;
        }
    }
}
