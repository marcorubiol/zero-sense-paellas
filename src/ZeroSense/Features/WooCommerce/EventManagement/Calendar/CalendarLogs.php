<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Calendar;

use WC_Order;

class CalendarLogs
{
    private const META_KEY = 'zs_calendar_logs';
    private const MAX_ENTRIES = 100;

    /**
     * Add a calendar sync log entry to an order
     *
     * @param WC_Order $order The order to add the log to
     * @param string $type Log type: 'created', 'updated', 'error', 'synced'
     * @param array $data Additional data for the log entry
     */
    public static function add(WC_Order $order, string $type, array $data = []): void
    {
        try {
            $entry = [
                'type' => $type,
                'timestamp' => current_time('mysql'),
                'status' => $order->get_status(),
                'data' => $data,
            ];

            $logs = $order->get_meta(self::META_KEY, true);
            $logs = is_array($logs) ? $logs : [];

            // Prepend newest
            array_unshift($logs, $entry);
            if (count($logs) > self::MAX_ENTRIES) {
                $logs = array_slice($logs, 0, self::MAX_ENTRIES);
            }

            $order->update_meta_data(self::META_KEY, $logs);
            $order->save_meta_data();
        } catch (\Throwable $e) {
            // Silent by design
        }
    }

    /**
     * Get all calendar sync logs for an order
     *
     * @param WC_Order $order The order to get logs for
     * @return array Array of log entries
     */
    public static function getForOrder(WC_Order $order): array
    {
        $logs = $order->get_meta(self::META_KEY, true);
        return is_array($logs) ? $logs : [];
    }
}

/**
 * Global helper function for FlowMattic to save Google Calendar Event ID
 * 
 * Usage in FlowMattic "Call PHP Function":
 * Function Name: zs_save_calendar_event_id
 * Parameters: 
 *   - order_id: {{order_id}}
 *   - event_id: {{event_id}}
 *   - event_title: {{event_title}} (optional)
 * 
 * @param array $params Array with keys: order_id, event_id, event_title (optional)
 * @return array Response with success status and message
 */
if (!function_exists('zs_save_calendar_event_id')) {
    function zs_save_calendar_event_id(array $params): array
    {
        try {
            $orderId = isset($params['order_id']) ? absint($params['order_id']) : 0;
            $eventId = isset($params['event_id']) ? sanitize_text_field($params['event_id']) : '';
            $eventTitle = isset($params['event_title']) ? sanitize_text_field($params['event_title']) : '';

            if ($orderId === 0) {
                return [
                    'success' => false,
                    'message' => 'Invalid order ID',
                ];
            }

            if ($eventId === '') {
                return [
                    'success' => false,
                    'message' => 'Invalid event ID',
                ];
            }

            $order = wc_get_order($orderId);
            if (!$order instanceof \WC_Order) {
                return [
                    'success' => false,
                    'message' => 'Order not found',
                ];
            }

            // Save event ID
            $order->update_meta_data('zs_google_calendar_event_id', $eventId);
            $order->save_meta_data();

            // Add log entry
            $logData = [
                'event_id' => $eventId,
                'trigger_source' => 'automatic',
            ];
            
            if ($eventTitle !== '') {
                $logData['event_title'] = $eventTitle;
            }

            \ZeroSense\Features\WooCommerce\EventManagement\Calendar\CalendarLogs::add(
                $order,
                'created',
                $logData
            );

            return [
                'success' => true,
                'message' => 'Event ID saved successfully',
                'order_id' => $orderId,
                'event_id' => $eventId,
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }
}
