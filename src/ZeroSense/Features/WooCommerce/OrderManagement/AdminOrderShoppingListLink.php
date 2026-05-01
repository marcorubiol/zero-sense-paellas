<?php
declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce\OrderManagement;

use WC_Order;
use ZeroSense\Core\FeatureInterface;
use ZeroSense\Features\WooCommerce\ShoppingList;

class AdminOrderShoppingListLink implements FeatureInterface
{
    public function getName(): string        { return __('Admin Order Shopping List Link', 'zero-sense'); }
    public function getDescription(): string { return __('Adds a shopping cart icon in the orders list linking to the shopping list page pre-filtered for that order.', 'zero-sense'); }
    public function getCategory(): string    { return 'WooCommerce'; }
    public function isToggleable(): bool     { return true; }
    public function isEnabled(): bool
    {
        $shoppingList = new ShoppingList();
        if (!$shoppingList->isEnabled()) {
            return false;
        }
        return (bool) get_option($this->getOptionName(), true);
    }
    public function getOptionName(): string  { return 'zs_feature_admin_order_shopping_list_link'; }
    public function getPriority(): int       { return 10; }
    public function getConditions(): array   { return ['is_admin', 'class_exists:WooCommerce']; }

    public function init(): void
    {
        add_action('manage_shop_order_posts_custom_column', [$this, 'renderInOrderColumn'], 10, 2);
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'renderInOrderColumnHpos'], 10, 2);
    }

    public function renderInOrderColumn(string $column, int $postId): void
    {
        if ($column !== 'order_number') { return; }
        $order = wc_get_order($postId);
        if (!$order instanceof WC_Order) { return; }
        $this->outputLink($order);
    }

    public function renderInOrderColumnHpos(string $column, WC_Order $order): void
    {
        if ($column !== 'order_number') { return; }
        $this->outputLink($order);
    }

    private function outputLink(WC_Order $order): void
    {
        $allowedStatuses = ['pending', 'deposit-paid', 'fully-paid', 'wc-pending', 'wc-deposit-paid', 'wc-fully-paid'];
        if (!in_array($order->get_status(), $allowedStatuses, true)) {
            return;
        }

        $eventDate = (string) $order->get_meta('zs_event_date', true);
        if ($eventDate === '') { return; }

        $shoppingList = new ShoppingList();
        $loc = (int) $order->get_meta('zs_event_service_location', true);
        if ($loc <= 0) { return; }

        $url = $shoppingList->buildSignedUrl($eventDate, $eventDate, $loc, [$order->get_id()]);
        if ($url === '') { return; }

        printf(
            '<a href="%s" target="_blank" title="%s" class="zs-shopping-list-link" style="margin-left:4px;">
                <span class="dashicons dashicons-cart"></span>
            </a>',
            esc_url($url),
            esc_attr__('View shopping list for this order', 'zero-sense')
        );
    }
}
