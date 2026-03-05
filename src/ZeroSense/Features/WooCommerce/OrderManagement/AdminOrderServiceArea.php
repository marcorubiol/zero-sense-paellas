<?php
namespace ZeroSense\Features\WooCommerce\OrderManagement;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;

if (!defined('ABSPATH')) { exit; }

class AdminOrderServiceArea implements FeatureInterface
{
    private const TAXONOMY = 'service-area';

    public function getName(): string
    {
        return __('Orders: Service Area Column', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Adds a filterable Service Area column to WooCommerce Orders showing the service area taxonomy term.', 'zero-sense');
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
        return 'zs_admin_order_service_area';
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

        // Filters
        add_action('restrict_manage_posts', [$this, 'renderClassicFilter'], 10);
        add_action('woocommerce_order_list_table_restrict_manage_orders', [$this, 'renderHposFilter'], 10);
        add_filter('request', [$this, 'handleClassicFilter']);
        add_filter('woocommerce_shop_order_list_table_query_args', [$this, 'handleHposFilter']);
    }

    public function registerColumn(array $columns): array
    {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            // Insert after event_date if it exists, otherwise after order_status
            if ($key === 'event_date' || ($key === 'order_status' && !isset($columns['event_date']))) {
                $new['service_area'] = __('Service Area', 'zero-sense');
            }
        }
        
        // Fallback if neither event_date nor order_status exist
        if (!isset($new['service_area'])) {
            $new['service_area'] = __('Service Area', 'zero-sense');
        }
        
        return $new;
    }

    public function renderClassic(string $column, int $postId): void
    {
        if ($column !== 'service_area') {
            return;
        }

        $order = wc_get_order($postId);
        if (!$order instanceof \WC_Order) {
            echo '<span class="na">&ndash;</span>';
            return;
        }

        $this->renderAreaCell($order);
    }

    public function renderHpos(string $column, $order): void
    {
        if ($column !== 'service_area') {
            return;
        }

        if (!$order instanceof \WC_Order) {
            echo '<span class="na">&ndash;</span>';
            return;
        }

        $this->renderAreaCell($order);
    }

    private function renderAreaCell(\WC_Order $order): void
    {
        $termId = (int) $order->get_meta(MetaKeys::SERVICE_LOCATION, true);
        
        if ($termId <= 0) {
            echo '<span class="na">&ndash;</span>';
            return;
        }

        $term = get_term($termId, self::TAXONOMY);
        
        if (!$term instanceof \WP_Term || is_wp_error($term)) {
            echo '<span class="na">&ndash;</span>';
            return;
        }

        echo '<span class="zs-service-area">' . esc_html($term->name) . '</span>';
    }

    public function renderClassicFilter(): void
    {
        global $typenow;
        
        if ($typenow !== 'shop_order') {
            return;
        }

        $this->renderFilterDropdown();
    }

    public function renderHposFilter(): void
    {
        $this->renderFilterDropdown();
    }

    private function renderFilterDropdown(): void
    {
        $terms = get_terms([
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return;
        }

        $selected = isset($_GET['zs_service_area']) ? (int) $_GET['zs_service_area'] : 0;

        echo '<select name="zs_service_area" id="zs_service_area">';
        echo '<option value="">' . esc_html__('All service areas', 'zero-sense') . '</option>';
        
        foreach ($terms as $term) {
            printf(
                '<option value="%d"%s>%s</option>',
                $term->term_id,
                selected($selected, $term->term_id, false),
                esc_html($term->name)
            );
        }
        
        echo '</select>';
    }

    public function handleClassicFilter(array $vars): array
    {
        global $typenow;
        
        if ($typenow !== 'shop_order' || !isset($_GET['zs_service_area']) || empty($_GET['zs_service_area'])) {
            return $vars;
        }

        $termId = (int) $_GET['zs_service_area'];
        if ($termId <= 0) {
            return $vars;
        }

        // Get all order IDs with this service area
        $orderIds = $this->getOrderIdsByServiceArea($termId);
        
        if (empty($orderIds)) {
            // No orders found, return impossible condition
            $vars['post__in'] = [0];
        } else {
            $vars['post__in'] = $orderIds;
        }

        return $vars;
    }

    public function handleHposFilter(array $args): array
    {
        if (!isset($_GET['zs_service_area']) || empty($_GET['zs_service_area'])) {
            return $args;
        }

        $termId = (int) $_GET['zs_service_area'];
        if ($termId <= 0) {
            return $args;
        }

        $mq = isset($args['meta_query']) && is_array($args['meta_query']) ? $args['meta_query'] : [];
        $mq[] = [
            'key' => MetaKeys::SERVICE_LOCATION,
            'value' => $termId,
            'compare' => '=',
            'type' => 'NUMERIC',
        ];
        $args['meta_query'] = $mq;

        return $args;
    }

    private function getOrderIdsByServiceArea(int $termId): array
    {
        global $wpdb;
        
        // Query postmeta for orders with this service area
        $sql = $wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = %s AND meta_value = %d",
            MetaKeys::SERVICE_LOCATION,
            $termId
        );
        
        $results = $wpdb->get_col($sql);
        
        return array_map('intval', $results);
    }
}
