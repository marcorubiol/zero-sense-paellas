<?php
namespace ZeroSense\Features\WooCommerce\Deposits\Support;

use WC_Order;

class Logs
{
    private const META_KEY = 'zs_deposits_logs';
    private const MAX_ENTRIES = 400;

    public static function add(WC_Order $order, string $type, array $data = []): void
    {
        try {
            // Auto-annotate with current user (admin) when available
            if (!isset($data['_by'])) {
                if (function_exists('is_user_logged_in') && is_user_logged_in()) {
                    $u = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
                    if ($u && isset($u->ID) && (int) $u->ID > 0) {
                        $data['_by'] = [
                            'id' => (int) $u->ID,
                            'name' => isset($u->display_name) ? (string) $u->display_name : '',
                            'login' => isset($u->user_login) ? (string) $u->user_login : '',
                        ];
                    }
                }
            }

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

    public static function getForOrder(WC_Order $order): array
    {
        $logs = $order->get_meta(self::META_KEY, true);
        return is_array($logs) ? $logs : [];
    }
}
