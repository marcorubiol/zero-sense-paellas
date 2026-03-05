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
        error_log('[CalendarAutoSync] Registering hooks...');
        
        // Use hook that fires after order is saved (works with both HPOS and legacy)
        add_action('woocommerce_after_order_object_save', [$this, 'onOrderSaved'], 10, 1);
        
        // Auto-mark as reserved when order is paid
        add_action('woocommerce_order_status_deposit-paid', [$this, 'markAsReserved'], 10, 1);
        add_action('woocommerce_order_status_fully-paid', [$this, 'markAsReserved'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'markAsReserved'], 10, 1);
        
        error_log('[CalendarAutoSync] Hooks registered successfully');
    }

    /**
     * Handle order saved (works with both HPOS and legacy storage)
     */
    public function onOrderSaved(\WC_Order $order): void
    {
        static $processedOrders = [];
        
        $orderId = $order->get_id();
        
        // Prevent multiple executions in the same request
        if (isset($processedOrders[$orderId])) {
            error_log('[CalendarAutoSync] Order ' . $orderId . ' already processed in this request, skipping');
            return;
        }
        
        error_log('[CalendarAutoSync] Order saved: ' . $orderId);

        // Check if order is in allowed status
        $allowedStatuses = ['pending', 'deposit-paid', 'fully-paid', 'completed'];
        $currentStatus = $order->get_status();
        if (!in_array($currentStatus, $allowedStatuses, true)) {
            error_log('[CalendarAutoSync] Status not allowed: ' . $currentStatus);
            return;
        }

        error_log('[CalendarAutoSync] Status OK: ' . $currentStatus);

        // Check if has event ID
        $eventId = $order->get_meta(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, true);
        if (!$eventId || $eventId === '') {
            error_log('[CalendarAutoSync] No event ID found');
            return;
        }

        error_log('[CalendarAutoSync] Event ID found: ' . $eventId);
        
        // Mark this order as processed in this request
        $processedOrders[$orderId] = true;
        
        error_log('[CalendarAutoSync] Triggering sync for order ' . $orderId);

        // Mark as needing sync (don't call save() to avoid infinite loop)
        $order->update_meta_data(MetaKeys::CALENDAR_NEEDS_SYNC, 'yes');

        // Trigger FlowMattic workflow
        do_action('zs_trigger_class_action_direct', 'zs-calendar-sync', $orderId, 'automatic');
        
        error_log('[CalendarAutoSync] Workflow triggered successfully');
    }

    /**
     * Mark event as reserved when order status changes to paid
     */
    public function markAsReserved(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        // Only process if order has a calendar event
        $eventId = $order->get_meta(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, true);
        if (!$eventId || $eventId === '') {
            return;
        }

        // Skip if already marked as reserved (idempotent)
        if ($order->get_meta(MetaKeys::EVENT_RESERVED, true) === 'yes') {
            return;
        }

        // Mark as reserved
        $order->update_meta_data(MetaKeys::EVENT_RESERVED, 'yes');
        $order->save_meta_data();

        // Add log entry
        if (class_exists('\\ZeroSense\\Features\\WooCommerce\\EventManagement\\Calendar\\CalendarLogs')) {
            $logData = [
                'event_id' => $eventId,
                'trigger_source' => 'automatic',
            ];

            \ZeroSense\Features\WooCommerce\EventManagement\Calendar\CalendarLogs::add(
                $order,
                'reserved',
                $logData
            );
        }

        // Trigger sync workflow to update calendar title (remove "PRE |")
        do_action('zs_trigger_class_action_direct', 'zs-calendar-sync', $orderId, 'automatic');
    }
}
