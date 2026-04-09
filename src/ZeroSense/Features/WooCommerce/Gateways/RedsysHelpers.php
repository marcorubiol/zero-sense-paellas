<?php
namespace ZeroSense\Features\WooCommerce\Gateways;

use WC_Order;

class RedsysHelpers
{
    public static function formatAmount(float $amount): string
    {
        $formatted = number_format($amount, 2, '.', '');
        return str_replace('.', '', $formatted);
    }

    // Redsys requires up to 12 chars; keep order id + separator + time tail
    public static function generateOrderId(int $orderId): string
    {
        $orderIdStr   = (string) $orderId;
        $suffixLength = 12 - strlen($orderIdStr) - 1;
        if ($suffixLength < 0) {
            return substr($orderIdStr, 0, 12);
        }
        $suffix = substr((string) time(), -$suffixLength);
        return $orderIdStr . 'T' . $suffix;
    }

    public static function getCurrencyNumericCode(string $currency): string
    {
        return match (strtoupper($currency)) {
            'EUR' => '978',
            'USD' => '840',
            'GBP' => '826',
            'MXN' => '484',
            'COP' => '170',
            default => '978',
        };
    }

    public static function getOkUrl(WC_Order $order): string
    {
        return $order->get_checkout_order_received_url();
    }

    public static function getKoUrl(): string
    {
        return function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/');
    }

    public static function getMerchantUrl(string $endpointId): string
    {
        $endpoint = 'wc_' . $endpointId;

        // Switch to default language so home_url() returns the root URL
        // without WPML prefix — S2S callbacks must be language-neutral
        $switched = false;
        if (has_action('wpml_switch_language')) {
            $switched = true;
            do_action('wpml_switch_language', apply_filters('wpml_default_language', null));
        }

        $url = home_url('/wc-api/' . $endpoint . '/');

        if ($switched) {
            do_action('wpml_switch_language', null);
        }

        return $url;
    }
}
