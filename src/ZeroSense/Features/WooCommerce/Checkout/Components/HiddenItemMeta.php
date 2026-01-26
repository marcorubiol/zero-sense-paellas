<?php
namespace ZeroSense\Features\WooCommerce\Checkout\Components;

use WC_Order_Item;

class HiddenItemMeta
{
    /** @var array<string> */
    private array $keysToHide = [
        'master_id',
        'master_id_key',
        'master_id_value',
    ];

    public function __construct()
    {
        // Hide specific meta keys globally in frontend displays (order details, emails, etc.)
        add_filter('woocommerce_hidden_order_itemmeta', [$this, 'hideOrderItemMeta'], 10, 1);
        // As a safety net, also strip them from the formatted meta output used by templates
        add_filter('woocommerce_order_item_get_formatted_meta_data', [$this, 'filterFormattedMeta'], 10, 2);
    }

    /**
     * @param array<string> $hidden
     * @return array<string>
     */
    public function hideOrderItemMeta(array $hidden): array
    {
        foreach ($this->keysToHide as $key) {
            if (!in_array($key, $hidden, true)) {
                $hidden[] = $key;
            }
        }
        return $hidden;
    }

    /**
     * Remove the keys from formatted meta in case a theme/template ignores hidden list.
     *
     * @param array<int,\WC_Meta_Data> $formatted
     * @param WC_Order_Item            $item
     * @return array<int,\WC_Meta_Data>
     */
    public function filterFormattedMeta(array $formatted, WC_Order_Item $item): array
    {
        if (empty($formatted)) {
            return $formatted;
        }

        $filtered = [];
        foreach ($formatted as $meta) {
            // WC_Meta_Data exposes ->display_key for formatted output
            $display_key = isset($meta->display_key) ? (string) $meta->display_key : '';
            $raw_key     = isset($meta->key) ? (string) $meta->key : $display_key;

            if (in_array($display_key, $this->keysToHide, true) || in_array($raw_key, $this->keysToHide, true)) {
                continue; // skip hidden keys
            }
            $filtered[] = $meta;
        }
        return $filtered;
    }
}
