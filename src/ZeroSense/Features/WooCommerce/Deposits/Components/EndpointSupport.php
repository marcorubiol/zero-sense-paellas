<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Components;

class EndpointSupport
{
    private const OPTION_ENDPOINTS_FIXED = 'zs_deposits_endpoints_fixed';

    public function register(): void
    {
        add_action('init', [$this, 'ensureEndpoints'], 999);
        add_filter('woocommerce_valid_order_statuses', [$this, 'appendCustomStatuses'], 20);
        add_filter('woocommerce_reports_order_statuses', [$this, 'appendCustomStatuses'], 20);
        add_filter('woocommerce_order_is_paid_statuses', [$this, 'appendCustomStatuses'], 20);
    }

    public function ensureEndpoints(): void
    {
        if (get_option(self::OPTION_ENDPOINTS_FIXED, 'no') === 'yes') {
            return;
        }

        if (!function_exists('WC')) {
            return;
        }

        WC()->query->init_query_vars();
        WC()->query->add_endpoints();
        flush_rewrite_rules();
        update_option(self::OPTION_ENDPOINTS_FIXED, 'yes');
    }

    public function appendCustomStatuses(array $statuses): array
    {
        $currentFilter = current_filter();

        if ($currentFilter !== 'woocommerce_order_is_paid_statuses' && !in_array('deposit-paid', $statuses, true)) {
            $statuses[] = 'deposit-paid';
        }

        if (!in_array('fully-paid', $statuses, true)) {
            $statuses[] = 'fully-paid';
        }

        return array_unique($statuses);
    }
}

