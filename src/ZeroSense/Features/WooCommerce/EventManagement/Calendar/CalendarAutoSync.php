<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Calendar;

use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;

/**
 * Auto-sync Google Calendar when order fields change
 */
class CalendarAutoSync
{
    /**
     * Fields that trigger calendar sync when changed
     */
    private const MONITORED_FIELDS = [
        '_billing_first_name',
        '_billing_last_name',
        'zs_event_service_location',
        'zs_event_paellas_service_time',
        'zs_event_adults',
        'zs_event_children_5_to_8',
        'zs_event_children_0_to_4',
        'zs_event_total_guests',
        'zs_event_staff',
        'zs_calendar_notes',
        'zs_event_date',
        '_shipping_address_1',
        '_shipping_city',
        'zs_event_start_time',
    ];

    /**
     * Throttle duration in seconds
     */
    private const THROTTLE_DURATION = 30;

    public function register(): void
    {
        add_action('updated_post_meta', [$this, 'onMetaUpdated'], 10, 4);
        add_action('added_post_meta', [$this, 'onMetaAdded'], 10, 4);
    }

    /**
     * Handle meta update
     */
    public function onMetaUpdated(int $metaId, int $objectId, string $metaKey, $metaValue): void
    {
        $this->handleMetaChange($objectId, $metaKey);
    }

    /**
     * Handle meta addition
     */
    public function onMetaAdded(int $metaId, int $objectId, string $metaKey, $metaValue): void
    {
        $this->handleMetaChange($objectId, $metaKey);
    }

    /**
     * Handle meta change and trigger sync if needed
     */
    private function handleMetaChange(int $objectId, string $metaKey): void
    {
        // Only process if meta key is in monitored list
        if (!in_array($metaKey, self::MONITORED_FIELDS, true)) {
            return;
        }

        // Verify this is a shop_order
        if (get_post_type($objectId) !== 'shop_order') {
            return;
        }

        $order = wc_get_order($objectId);
        if (!$order instanceof WC_Order) {
            return;
        }

        // Only sync if calendar event exists
        $eventId = $order->get_meta(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, true);
        if (!$eventId || $eventId === '') {
            return;
        }

        // Check throttle
        $throttleKey = 'zs_calendar_sync_throttle_' . $objectId;
        if (get_transient($throttleKey)) {
            return;
        }

        // Set throttle
        set_transient($throttleKey, true, self::THROTTLE_DURATION);

        // Mark as needing sync
        $order->update_meta_data(MetaKeys::CALENDAR_NEEDS_SYNC, 'yes');
        $order->save_meta_data();

        // Trigger FlowMattic workflow
        do_action('zs_trigger_class_action_direct', 'zs-calendar-sync', $objectId, 'automatic');
    }
}
