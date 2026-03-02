<?php
declare(strict_types=1);

namespace ZeroSense\Features\WooCommerce;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Features\WooCommerce\EventManagement\Calendar\CalendarLogMetabox;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;
use ZeroSense\Core\MetaFieldRegistry;

/**
 * Google Calendar Sync for WooCommerce Orders
 *
 * Provides integration with Google Calendar via FlowMattic to store event IDs,
 * track sync history, and display sync status in order admin.
 */
class GoogleCalendarSync implements FeatureInterface
{
    public function getName(): string
    {
        return __('Google Calendar Sync', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Stores Google Calendar event IDs in orders and provides sync logging. Includes metabox for viewing sync history and FlowMattic integration via zs_save_calendar_event_id() function.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isEnabled(): bool
    {
        return (bool) get_option($this->getOptionName(), true);
    }

    public function isToggleable(): bool
    {
        return true;
    }

    public function getOptionName(): string
    {
        return 'zs_feature_google_calendar_sync';
    }

    public function getPriority(): int
    {
        return 15;
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce'];
    }

    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // Register meta field
        $this->registerMetaField();

        // Register metabox
        (new CalendarLogMetabox())->register();
    }

    private function registerMetaField(): void
    {
        $registry = MetaFieldRegistry::getInstance();
        
        $registry->register(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, [
            'label' => __('Google Calendar event ID', 'zero-sense'),
            'type' => 'text',
            'translatable' => false,
            'legacy_keys' => [],
            'feature' => 'GoogleCalendarSync',
        ]);
    }
}
