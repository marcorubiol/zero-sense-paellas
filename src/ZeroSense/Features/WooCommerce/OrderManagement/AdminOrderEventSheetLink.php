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
        
        // Add CSS with inline-block positioning
        echo '<style>
            .zs-event-sheet-link {
                display: grid;
                place-items: center;
                width: 24px;
                height: 24px;
                padding: 2px;
                border: 2px solid transparent;
                border-radius: 4px;
                color: #2271b1;
                line-height: 1;
                margin-left: 2px;
                float: right;
            }
            .zs-event-sheet-link:hover {
                border: 2px solid var(--wp-admin-theme-color, #00a0d2);
            }
            .zs-event-sheet-link .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
                line-height: 1;
            }
            .order-preview {
                display: none;
            }
        </style>';
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
        
        // Debug log
        error_log('AdminOrderEventSheetLink: Looking for page with slug "fdr"');
        error_log('AdminOrderEventSheetLink: Page found: ' . ($page ? 'YES (ID: ' . $page->ID . ')' : 'NO'));
        
        if (!$page) {
            $page = get_page_by_path('hoja-de-ruta');
            error_log('AdminOrderEventSheetLink: Trying "hoja-de-ruta": ' . ($page ? 'YES' : 'NO'));
        }
        
        if (!$page) {
            $page = get_page_by_path('event-sheet');
            error_log('AdminOrderEventSheetLink: Trying "event-sheet": ' . ($page ? 'YES' : 'NO'));
        }

        if (!$page) {
            error_log('AdminOrderEventSheetLink: No page found, returning empty');
            return '';
        }

        $url = get_permalink($page->ID);
        error_log('AdminOrderEventSheetLink: Permalink: ' . $url);
        
        if (!$url) {
            return '';
        }

        $finalUrl = add_query_arg('zs_event_token', $token, $url);
        error_log('AdminOrderEventSheetLink: Final URL: ' . $finalUrl);
        
        return $finalUrl;
    }
}
