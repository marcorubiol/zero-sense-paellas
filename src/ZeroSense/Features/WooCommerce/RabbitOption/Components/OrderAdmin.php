<?php
namespace ZeroSense\Features\WooCommerce\RabbitOption\Components;

use ZeroSense\Features\WooCommerce\RabbitOption\Support\MetaKeys;

class OrderAdmin
{
    public function register(): void
    {
        // Readable label for the rabbit choice in admin order item meta
        add_filter('woocommerce_order_item_display_meta_key', [$this, 'formatMetaKey'], 10, 3);
        add_filter('woocommerce_order_item_display_meta_value', [$this, 'formatMetaValue'], 10, 3);
    }

    public function formatMetaKey(string $displayKey, $meta, $item): string
    {
        if (isset($meta->key) && $meta->key === MetaKeys::RABBIT_CHOICE) {
            return __('Rabbit', 'zero-sense');
        }
        return $displayKey;
    }

    public function formatMetaValue(string $displayValue, $meta, $item): string
    {
        if (isset($meta->key) && $meta->key === MetaKeys::RABBIT_CHOICE) {
            return $displayValue === 'with'
                ? '✓ ' . __('With rabbit', 'zero-sense')
                : '✗ ' . __('Without rabbit', 'zero-sense');
        }
        return $displayValue;
    }
}
