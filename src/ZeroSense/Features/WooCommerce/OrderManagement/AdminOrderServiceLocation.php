<?php
namespace ZeroSense\Features\WooCommerce\OrderManagement;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;
use ZeroSense\Features\WooCommerce\EventManagement\Support\FieldOptions;

if (!defined('ABSPATH')) { exit; }

class AdminOrderServiceLocation implements FeatureInterface
{
    public function getName(): string
    {
        return __('Orders: Service Location Column', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Adds a Service Location column to WooCommerce Orders showing the event location type (At home, At venue, Outdoor, Other).', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isToggleable(): bool
    {
        return true;
    }

    public function getOptionName(): string
    {
        return 'zs_admin_order_service_location';
    }

    public function isEnabled(): bool
    {
        return (bool) get_option($this->getOptionName(), true);
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getConditions(): array
    {
        return ['is_admin', 'class_exists:WooCommerce'];
    }

    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // Column registration (classic + HPOS)
        add_filter('manage_edit-shop_order_columns', [$this, 'registerColumn'], 9999);
        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'registerColumn'], 9999);

        // Renderers
        add_action('manage_shop_order_posts_custom_column', [$this, 'renderClassic'], 10, 2);
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'renderHpos'], 10, 2);
    }

    public function registerColumn(array $columns): array
    {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            // Insert after event_date if it exists, otherwise after order_status
            if ($key === 'event_date' || ($key === 'order_status' && !isset($columns['event_date']))) {
                $new['service_location'] = __('Service Location', 'zero-sense');
            }
        }
        
        // Fallback if neither event_date nor order_status exist
        if (!isset($new['service_location'])) {
            $new['service_location'] = __('Service Location', 'zero-sense');
        }
        
        return $new;
    }

    public function renderClassic(string $column, int $postId): void
    {
        if ($column !== 'service_location') {
            return;
        }

        $order = wc_get_order($postId);
        if (!$order instanceof \WC_Order) {
            echo '<span class="na">&ndash;</span>';
            return;
        }

        $this->renderLocationCell($order);
    }

    public function renderHpos(string $column, $order): void
    {
        if ($column !== 'service_location') {
            return;
        }

        if (!$order instanceof \WC_Order) {
            echo '<span class="na">&ndash;</span>';
            return;
        }

        $this->renderLocationCell($order);
    }

    private function renderLocationCell(\WC_Order $order): void
    {
        $locationKey = $order->get_meta(MetaKeys::SERVICE_LOCATION, true);
        
        if (empty($locationKey)) {
            echo '<span class="na">&ndash;</span>';
            return;
        }

        $options = FieldOptions::getServiceLocationOptions();
        $locationName = $options[$locationKey] ?? $locationKey;

        echo '<span class="zs-service-location">' . esc_html($locationName) . '</span>';
    }
}
