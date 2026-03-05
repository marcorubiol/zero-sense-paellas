<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Calendar;

use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;

/**
 * Auto-sync Google Calendar when monitored order fields change
 * Compatible with both HPOS and legacy post storage
 */
class CalendarAutoSync
{
    /**
     * Fields that trigger calendar sync when changed
     */
    public const MONITORED_FIELDS = [
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

    public function register(): void
    {
        // Prevent duplicate hook registrations
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        // Auto-sync when order is saved (HPOS-compatible)
        add_action('woocommerce_after_order_object_save', [$this, 'onOrderSaved'], 10, 1);

        // Auto-mark as reserved when order is paid
        add_action('woocommerce_order_status_deposit-paid', [$this, 'markAsReserved'], 10, 1);
        add_action('woocommerce_order_status_fully-paid', [$this, 'markAsReserved'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'markAsReserved'], 10, 1);
    }

    /**
     * Compute hash of all monitored field values for change detection
     */
    public static function computeFieldsHash(\WC_Order $order): string
    {
        $values = [];
        foreach (self::MONITORED_FIELDS as $key) {
            $values[$key] = (string) $order->get_meta($key, true);
        }
        return md5(serialize($values));
    }

    /**
     * Auto-sync calendar when admin saves an order and monitored fields changed
     * Defers trigger to shutdown so all meta (MetaBox, etc.) is saved before FlowMattic reads it
     */
    public function onOrderSaved(\WC_Order $order): void
    {
        static $scheduled = [];
        $orderId = $order->get_id();

        // Only schedule once per order per request
        if (isset($scheduled[$orderId])) {
            return;
        }

        // Only fire on admin form submissions (not AJAX, not cron, not page loads)
        if (!is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }

        // Only sync orders in allowed statuses
        $allowedStatuses = ['pending', 'deposit-paid', 'fully-paid', 'completed'];
        if (!in_array($order->get_status(), $allowedStatuses, true)) {
            return;
        }

        // Only sync if calendar event exists
        $eventId = $order->get_meta(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, true);
        if (!$eventId || $eventId === '') {
            return;
        }

        // Mark as scheduled to prevent duplicate registrations
        $scheduled[$orderId] = true;

        // Defer to shutdown: all plugins (MetaBox, etc.) have saved their meta by then
        add_action('shutdown', function () use ($orderId) {
            // Re-read order fresh from DB with ALL updated meta
            $freshOrder = wc_get_order($orderId);
            if (!$freshOrder instanceof \WC_Order) {
                return;
            }

            // Only sync if monitored fields actually changed
            $previousHash = sanitize_text_field($_POST['_zs_calendar_fields_hash'] ?? '');
            if ($previousHash !== '' && $previousHash === self::computeFieldsHash($freshOrder)) {
                return;
            }

            // Log the auto-sync — no FlowMattic call needed for logging
            $eventId = $freshOrder->get_meta(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, true);
            CalendarLogs::add($freshOrder, 'updated', [
                'event_id' => $eventId,
                'trigger_source' => 'automatic',
            ]);

            do_action('zs_trigger_class_action_direct', 'zs-calendar-sync', $orderId, 'automatic');
        });
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
            CalendarLogs::add($order, 'reserved', [
                'event_id' => $eventId,
                'trigger_source' => 'automatic',
            ]);
        }

        // Trigger sync workflow to update calendar title (remove "PRE |")
        do_action('zs_trigger_class_action_direct', 'zs-calendar-sync', $orderId, 'automatic');
    }
}
