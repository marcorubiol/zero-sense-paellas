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
        // Classic orders list - add icon to order column
        add_action('manage_shop_order_posts_custom_column', [$this, 'renderInOrderColumn'], 10, 2);

        // HPOS orders list - add icon to order column
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'renderInOrderColumnHpos'], 10, 2);
    }

    public function renderInOrderColumn(string $column, int $postId): void
    {
        if ($column !== 'order_number') {
            return;
        }

        $order = wc_get_order($postId);
        if (!$order instanceof WC_Order) {
            return;
        }

        $this->outputLink($order);
    }

    public function renderInOrderColumnHpos(string $column, WC_Order $order): void
    {
        if ($column !== 'order_number') {
            return;
        }

        $this->outputLink($order);
    }

    private function outputLink(WC_Order $order): void
    {
        // Ensure order has a token (generate if missing)
        $this->ensureOrderHasToken($order);
        
        $token = $order->get_meta('zs_event_public_token', true);
        
        if (!is_string($token) || $token === '') {
            echo '<span style="color: #999;">—</span>';
            return;
        }

        // Build URL manually to ensure correct page
        $url = $this->buildEventSheetUrl($order->get_id(), $token);

        if (!$url || $url === '') {
            echo '<span style="color: #999;">—</span>';
            return;
        }

        printf(
            '<a href="%s" target="_blank" title="%s" class="zs-event-sheet-link">
                <span class="dashicons dashicons-clipboard"></span>
            </a> ',
            esc_url($url),
            esc_attr__('View event sheet', 'zero-sense')
        );
    }

    private function ensureOrderHasToken(WC_Order $order): void
    {
        $existing = $order->get_meta('zs_event_public_token', true);
        if (is_string($existing) && $existing !== '') {
            return;
        }

        // Generate token for old orders
        $token = $this->generateToken();
        $order->update_meta_data('zs_event_public_token', $token);
        $order->save();
    }

    private function generateToken(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return wp_generate_password(32, false, false);
        }
    }

    private function buildEventSheetUrl(int $orderId, string $token): string
    {
        // Try to find the event sheet page
        $page = get_page_by_path('fdr');
        
        if (!$page) {
            $page = get_page_by_path('hoja-de-ruta');
        }
        
        if (!$page) {
            $page = get_page_by_path('event-sheet');
        }

        if (!$page) {
            return '';
        }

        $url = get_permalink($page->ID);
        
        if (!$url) {
            return '';
        }

        $finalUrl = add_query_arg('zs_event_token', $token, $url);
        
        return $finalUrl;
    }
}
