<?php
namespace ZeroSense\Features\WooCommerce\Checkout\Components;

use WP_Error;

class CheckoutTermsHandler
{
    public function __construct()
    {
        // Use WooCommerce filter instead of modifying $_POST superglobal
        add_filter('woocommerce_checkout_posted_data', [$this, 'force_accept_terms'], 1);
        add_filter('woocommerce_checkout_show_terms', [$this, 'maybe_hide_terms'], 999);
    }

    /**
     * Force terms acceptance using proper WooCommerce filter
     * 
     * @param array $data Checkout posted data
     * @return array Modified data with terms accepted
     */
    public function force_accept_terms(array $data): array
    {
        if (!$this->is_primary_checkout()) {
            return $data;
        }

        if (empty($data['terms'])) {
            $data['terms'] = '1';
        }
        
        return $data;
    }

    public function maybe_hide_terms(bool $show): bool
    {
        if (!$this->is_primary_checkout()) {
            return $show;
        }

        return false;
    }

    private function is_primary_checkout(): bool
    {
        if (!function_exists('is_checkout')) {
            return false;
        }

        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            return false;
        }

        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
            return false;
        }

        if (isset($_GET['pay_for_order'])) {
            return false;
        }

        return is_checkout();
    }
}


