<?php
namespace ZeroSense\Features\WooCommerce\Checkout\Components;

class DateTimePicker
{
    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_datepicker_assets']);
    }

    /**
     * Enqueue Flatpickr assets for checkout and order-pay pages
     */
    public function enqueue_datepicker_assets()
    {
        if ((is_checkout() && !is_wc_endpoint_url('order-received')) || is_wc_endpoint_url('order-pay')) {
            // Enqueue Flatpickr CSS & JS from CDN
            wp_enqueue_style(
                'flatpickr',
                'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css',
                [],
                null
            );
            
            wp_enqueue_script(
                'flatpickr',
                'https://cdn.jsdelivr.net/npm/flatpickr',
                [],
                null,
                true
            );
            
            // Enqueue our initialization script (after Flatpickr)
            wp_enqueue_script(
                'zs-checkout-datepicker',
                plugins_url('src/ZeroSense/Features/WooCommerce/assets/checkout-datepicker.js', ZERO_SENSE_FILE),
                ['jquery', 'flatpickr'],
                defined('ZERO_SENSE_VERSION') ? ZERO_SENSE_VERSION : '1.0.0',
                true
            );
        }
    }
}
