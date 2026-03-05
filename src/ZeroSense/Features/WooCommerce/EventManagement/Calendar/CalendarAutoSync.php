<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Calendar;

use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;

/**
 * Auto-sync Google Calendar when order is saved from admin
 * Compatible with both HPOS and legacy post storage
 */
class CalendarAutoSync
{
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
     * Auto-sync calendar when admin saves an order
     */
    public function onOrderSaved(\WC_Order $order): void
    {
        // Once per order per request
        static $processed = [];
        $orderId = $order->get_id();
        if (isset($processed[$orderId])) {
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

        // Mark as processed BEFORE triggering to prevent re-entry
        $processed[$orderId] = true;

        // Remove our hook to prevent recursive triggers from FlowMattic saves
        remove_action('woocommerce_after_order_object_save', [$this, 'onOrderSaved'], 10);

        // Trigger FlowMattic workflow
        do_action('zs_trigger_class_action_direct', 'zs-calendar-sync', $orderId, 'automatic');

        // Re-add our hook
        add_action('woocommerce_after_order_object_save', [$this, 'onOrderSaved'], 10, 1);
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
