<?php
declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce\OrderManagement;

use WC_Order;
use ZeroSense\Core\FeatureInterface;

class AdminOrderEventSheetLink implements FeatureInterface
{
    public function getName(): string
    {
        return __('Admin Order Event Sheet Link', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Adds a direct link to the event route sheet in the orders list.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isToggleable(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
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
        // Classic orders list
        add_filter('manage_edit-shop_order_columns', [$this, 'addColumn']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'renderColumn'], 10, 2);

        // HPOS orders list
        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'addColumn']);
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'renderColumnHpos'], 10, 2);
    }

    public function addColumn(array $columns): array
    {
        $newColumns = [];
        
        foreach ($columns as $key => $label) {
            $newColumns[$key] = $label;
            
            // Add after order_number column
            if ($key === 'order_number') {
                $newColumns['event_sheet'] = __('Event Sheet', 'zero-sense');
            }
        }
        
        return $newColumns;
    }

    public function renderColumn(string $column, int $postId): void
    {
        if ($column !== 'event_sheet') {
            return;
        }

        $order = wc_get_order($postId);
        if (!$order instanceof WC_Order) {
            echo '—';
            return;
        }

        $this->outputLink($order);
    }

    public function renderColumnHpos(string $column, WC_Order $order): void
    {
        if ($column !== 'event_sheet') {
            return;
        }

        $this->outputLink($order);
    }

    private function outputLink(WC_Order $order): void
    {
        $token = $order->get_meta('zs_event_public_token', true);
        
        if (!is_string($token) || $token === '') {
            echo '<span style="color: #999;">—</span>';
            return;
        }

        // Get the event sheet page URL
        $pageId = $this->getEventSheetPageId();
        if (!$pageId) {
            echo '<span style="color: #999;">—</span>';
            return;
        }

        $url = get_permalink($pageId);
        if (!$url) {
            echo '<span style="color: #999;">—</span>';
            return;
        }

        // Add token to URL
        $url = add_query_arg('zs_event_token', $token, $url);

        printf(
            '<a href="%s" target="_blank" style="color: #2271b1; text-decoration: none;">
                <span class="dashicons dashicons-media-document" style="font-size: 18px; width: 18px; height: 18px;"></span>
                <span style="vertical-align: middle;">%s</span>
            </a>',
            esc_url($url),
            esc_html__('View', 'zero-sense')
        );
    }

    private function getEventSheetPageId(): ?int
    {
        // Try to find the event sheet page
        // You can customize this to match your actual page slug or ID
        $page = get_page_by_path('event-sheet');
        
        if (!$page) {
            $page = get_page_by_path('hoja-de-ruta');
        }
        
        if (!$page) {
            $page = get_page_by_path('event-information-sheet');
        }

        return $page ? $page->ID : null;
    }
}
