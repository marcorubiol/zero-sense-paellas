<?php
namespace ZeroSense\Features\WooCommerce\OrderPay\Components;

class NoticeClearing
{
    public function __construct()
    {
        add_action('template_redirect', [$this, 'maybeClearOrderPayNotices'], 1);
    }

    public function maybeClearOrderPayNotices(): void
    {
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            if (function_exists('wc_clear_notices')) {
                wc_clear_notices();
            }
            if (function_exists('WC') && WC()->session) {
                WC()->session->set('wc_notices', []);
            }
        }
    }
}
