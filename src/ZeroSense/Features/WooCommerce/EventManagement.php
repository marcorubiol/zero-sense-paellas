<?php
namespace ZeroSense\Features\WooCommerce;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Features\WooCommerce\EventManagement\Bootstrap;

/**
 * Event Management for WooCommerce Orders
 *
 * Provides comprehensive event data management for catering/event orders.
 * Includes guest information, location details, timing, and automatic data
 * exposure to Flowmattic and Bricks for seamless integration.
 */
class EventManagement implements FeatureInterface
{
    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return __('Event Management', 'zero-sense');
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return __('Manages event-specific data for orders including guest counts, location details, timing, and event type. Automatically exposes data to Flowmattic and Bricks.', 'zero-sense');
    }

    /**
     * {@inheritdoc}
     */
    public function getCategory(): string
    {
        return __('WooCommerce', 'zero-sense');
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        return get_option('zs_event_management_enabled', true);
    }

    /**
     * {@inheritdoc}
     */
    public function getOptionKey(): string
    {
        return 'zs_event_management_enabled';
    }

    /**
     * {@inheritdoc}
     */
    public function isToggleable(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority(): int
    {
        return 10;
    }

    /**
     * {@inheritdoc}
     */
    public function getConditions(): array
    {
        return ['class_exists:WooCommerce'];
    }

    /**
     * {@inheritdoc}
     */
    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // Bootstrap the module
        $bootstrap = new Bootstrap();
        $bootstrap->boot();
    }

    /**
     * {@inheritdoc}
     */
    public function getDependencies(): array
    {
        return ['woocommerce'];
    }
}
