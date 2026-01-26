<?php
namespace ZeroSense\Features\WooCommerce\OrderStatuses\Support;

use WC_Order;

class StatusLogs
{
    private const META_KEY = 'zs_order_status_logs';
    private const MAX_ENTRIES = 400;

    public static function add(WC_Order $order, array $data = []): void
    {
        try {
            $entry = [
                'from_status' => $data['from_status'] ?? '',
                'to_status' => $data['to_status'] ?? '',
                'trigger_source' => $data['trigger_source'] ?? 'unknown',
                'user_id' => $data['user_id'] ?? 0,
                'user_name' => $data['user_name'] ?? '',
                'note' => $data['note'] ?? '',
                'order_total' => $data['order_total'] ?? (float) $order->get_total(),
                'timestamp' => current_time('mysql'),
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

    public static function getForOrder(WC_Order $order): array
    {
        $logs = $order->get_meta(self::META_KEY, true);
        return is_array($logs) ? $logs : [];
    }
}
