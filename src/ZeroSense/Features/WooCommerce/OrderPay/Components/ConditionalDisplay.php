<?php
namespace ZeroSense\Features\WooCommerce\OrderPay\Components;

class ConditionalDisplay
{
    public function __construct()
    {
        // Run late to ensure we can dequeue any legacy scripts enqueued earlier
        add_action('wp_enqueue_scripts', [$this, 'enqueue_conditional_display_scripts'], 100);
    }

    /**
     * Enqueue scripts for conditional display on order pay page
     */
    public function enqueue_conditional_display_scripts()
    {
        // Only load on order pay page
        if (!is_wc_endpoint_url('order-pay')) {
            return;
        }

        // Get order from URL parameters
        $order_id = absint(get_query_var('order-pay'));
        if (!$order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $order_status = $order->get_status();
        
        // Dequeue/deregister legacy script (from old plugin) if present to avoid duplicate behaviors
        wp_dequeue_script('zs-order-pay-conditional-display');
        wp_deregister_script('zs-order-pay-conditional-display');

        // Enqueue v3 script with a unique handle
        wp_enqueue_script(
            'zs-order-pay-conditional-display-v3',
            plugins_url('src/ZeroSense/Features/WooCommerce/assets/order-pay-conditional-display.js', ZERO_SENSE_FILE),
            ['jquery'],
            defined('ZERO_SENSE_VERSION') ? ZERO_SENSE_VERSION : '1.0.0',
            true
        );

        // Prepare translatable Terms helper text
        $default_terms_text = __('Please accept the Terms & Conditions to continue.', 'zero-sense');
        $terms_helper_text = $default_terms_text;
        
        // Register string for WPML translation
        if (function_exists('do_action')) {
            do_action('wpml_register_single_string', 'zero-sense', 'order_pay_terms_helper', $default_terms_text);
        }
        
        if (function_exists('apply_filters')) {
            $terms_helper_text = apply_filters('wpml_translate_single_string', $default_terms_text, 'zero-sense', 'order_pay_terms_helper');
        }

        // Determine visibility logic
        $show_payment_options = in_array($order_status, ['pending', 'deposit-paid'], true);
        $show_make_reservation = ($order_status === 'pending');

        // Localize script with data
        wp_localize_script('zs-order-pay-conditional-display-v3', 'zsOrderPayData', [
            'orderStatus' => $order_status,
            'showPaymentOptions' => $show_payment_options,
            'showMakeReservation' => $show_make_reservation,
            'termsHelperText' => $terms_helper_text,
        ]);
    }
}
