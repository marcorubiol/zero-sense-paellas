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
