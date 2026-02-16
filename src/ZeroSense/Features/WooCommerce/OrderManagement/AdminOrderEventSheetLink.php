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
        // Ensure order has a token (generate if missing)
        $this->ensureOrderHasToken($order);
        
        $token = $order->get_meta('zs_event_public_token', true);
        
        if (!is_string($token) || $token === '') {
            echo '<span style="color: #999;">—</span>';
            return;
        }

        // Use shortcode to get the link (if EventPublicAccess feature is active)
        if (shortcode_exists('zs_event_public_link')) {
            $url = do_shortcode('[zs_event_public_link order="' . $order->get_id() . '"]');
        } else {
            // Fallback: build URL manually
            $url = $this->buildEventSheetUrl($order->get_id(), $token);
        }

        if (!$url || $url === '') {
            echo '<span style="color: #999;">—</span>';
            return;
        }

        printf(
            '<a href="%s" target="_blank" title="%s" style="color: #2271b1; text-decoration: none; display: inline-block;">
                <span class="dashicons dashicons-media-document" style="font-size: 18px; width: 18px; height: 18px; vertical-align: middle;"></span>
            </a>',
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

        return add_query_arg('zs_event_token', $token, $url);
    }
}
