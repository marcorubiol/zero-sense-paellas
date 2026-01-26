<?php
namespace ZeroSense\Utilities;

/**
 * HPOS (High-Performance Order Storage) Compatibility Helper
 * 
 * Provides centralized utilities to detect HPOS status and ensure
 * compatibility between Classic WooCommerce and HPOS systems.
 */
class HposCompatibility
{
    /**
     * Check if HPOS is enabled
     */
    public static function isHposEnabled(): bool
    {
        if (!class_exists('Automattic\WooCommerce\Utilities\OrderUtil')) {
            return false;
        }

        return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Get the current screen ID for orders
     * Returns 'shop_order' for Classic, 'woocommerce_page_wc-orders' for HPOS
     */
    public static function getOrderScreenId(): string
    {
        return self::isHposEnabled() ? 'woocommerce_page_wc-orders' : 'shop_order';
    }

    /**
     * Check if current screen is an order edit screen (Classic or HPOS)
     */
    public static function isOrderEditScreen(): bool
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }

        return in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true);
    }

    /**
     * Check if current screen is an order list screen (Classic or HPOS)
     */
    public static function isOrderListScreen(): bool
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }

        return in_array($screen->id, ['edit-shop_order', 'woocommerce_page_wc-orders'], true);
    }

    /**
     * Get order ID from various contexts (post, order object, or HPOS)
     */
    public static function getOrderId($orderOrPost): int
    {
        if (is_numeric($orderOrPost)) {
            return (int) $orderOrPost;
        }

        if ($orderOrPost instanceof \WC_Order) {
            return $orderOrPost->get_id();
        }

        if ($orderOrPost instanceof \WP_Post) {
            return $orderOrPost->ID;
        }

        return 0;
    }

    /**
     * Get order object from ID or post
     */
    public static function getOrder($orderOrPostId): ?\WC_Order
    {
        $orderId = self::getOrderId($orderOrPostId);
        if (!$orderId) {
            return null;
        }

        $order = wc_get_order($orderId);
        return $order instanceof \WC_Order ? $order : null;
    }
}
