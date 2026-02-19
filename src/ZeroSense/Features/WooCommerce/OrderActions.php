<?php
namespace ZeroSense\Features\WooCommerce;

use ZeroSense\Core\FeatureInterface;

/**
 * WooCommerce Order Actions Management
 * 
 * Removes order actions dropdown in HPOS order edit screen
 * while keeping Move to Trash and Update buttons.
 */
class OrderActions implements FeatureInterface
{
    public function __construct()
    {
        // Auto-discovery via FeatureManager - no manual hook registration needed
    }

    public function getName(): string
    {
        return 'Order Actions Management';
    }

    public function getDescription(): string
    {
        return 'Removes the "Choose an action..." dropdown from order edit screen while keeping Move to Trash and Update buttons.';
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isToggleable(): bool
    {
        return false; // Always-on feature
    }

    public function isEnabled(): bool
    {
        return true; // Always enabled
    }

    public function getPriority(): int
    {
        return 10; // Default priority
    }

    public function getConditions(): array
    {
        return ['is_admin', 'class_exists:WC_Order'];
    }

    public function init(): void
    {
        // Remove all order actions from the dropdown
        add_filter('woocommerce_order_actions', [$this, 'removeOrderActions'], 999, 2);
    }

    /**
     * Remove all order actions from the dropdown
     * This effectively hides the "Choose an action..." dropdown
     * while preserving Move to Trash and Update buttons
     * 
     * @param array $actions Available order actions
     * @param WC_Order $order Order object
     * @return array Empty array to remove all actions
     */
    public function removeOrderActions($actions, $order)
    {
        // Return empty array to remove all actions from dropdown
        return [];
    }
}
