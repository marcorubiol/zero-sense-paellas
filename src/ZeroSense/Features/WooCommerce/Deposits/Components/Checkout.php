<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Components;

class Checkout
{
    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_zs_deposits_update_order_pay_choice_session', [$this, 'handlePaymentChoiceUpdate']);
        add_action('wp_ajax_nopriv_zs_deposits_update_order_pay_choice_session', [$this, 'handlePaymentChoiceUpdate']);
    }

    public function enqueueAssets(): void
    {
        if (!function_exists('is_woocommerce')) {
            return;
        }

        if (is_woocommerce() || is_checkout() || is_wc_endpoint_url('order-pay')) {
            $handle = 'zs-deposits-payment-detection';
            $assetUrl = plugins_url('assets/js/woocommerce-deposits-payment-detection.js', ZERO_SENSE_FILE);
            $assetPath = plugin_dir_path(ZERO_SENSE_FILE) . 'assets/js/woocommerce-deposits-payment-detection.js';
            $version = defined('ZERO_SENSE_VERSION') ? ZERO_SENSE_VERSION : filemtime($assetPath);

            wp_enqueue_script($handle, $assetUrl, ['jquery'], $version, true);
        }

        if (!is_wc_endpoint_url('order-pay')) {
            return;
        }

        global $wp;
        $orderId = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        $order = $orderId ? wc_get_order($orderId) : null;
        if (!$order) {
            return;
        }

        // Provide minimal vars for order-pay page so JS can post the user choice
        wp_register_script('zs-deposits-inline-vars', '', [], null, true);
        wp_enqueue_script('zs-deposits-inline-vars');
        $vars = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'orderId' => (string) $orderId,
            'orderPayNonce' => wp_create_nonce('zs_deposits_order_pay_nonce'),
        ];
        wp_add_inline_script(
            'zs-deposits-inline-vars',
            'window.zsDepositsDepositVars = ' . wp_json_encode($vars) . ';',
            'before'
        );
    }

    public function handlePaymentChoiceUpdate(): void
    {
        $nonceField = isset($_POST['security']) ? sanitize_text_field(wp_unslash($_POST['security'])) : '';
        $nonceValid = wp_verify_nonce($nonceField, 'zs_deposits_order_pay_nonce');

        if (!$nonceValid) {
            wp_send_json_error(['message' => __('Invalid security token.', 'zero-sense')]);
        }

        $orderId = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $choice = isset($_POST['choice']) ? sanitize_key($_POST['choice']) : 'deposit';

        if (!$orderId || !in_array($choice, ['deposit', 'full'], true)) {
            wp_send_json_error(['message' => __('Invalid data received.', 'zero-sense')]);
        }

        if (WC()->session) {
            $key = 'zs_deposits_payment_choice_order_' . $orderId;
            WC()->session->set($key, $choice);
            wp_send_json_success(['message' => __('Choice updated.', 'zero-sense')]);
        }

        wp_send_json_error(['message' => __('Session not available.', 'zero-sense')]);
    }
}
