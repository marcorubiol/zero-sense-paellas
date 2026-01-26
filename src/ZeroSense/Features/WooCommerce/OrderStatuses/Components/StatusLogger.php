<?php
namespace ZeroSense\Features\WooCommerce\OrderStatuses\Components;

use WC_Order;
use ZeroSense\Features\WooCommerce\OrderStatuses\Support\StatusLogs;

class StatusLogger
{
    public function register(): void
    {
        add_action('woocommerce_order_status_changed', [$this, 'logStatusChange'], 10, 4);
    }

    public function logStatusChange(int $orderId, string $fromStatus, string $toStatus, $order): void
    {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($orderId);
        }
        
        if (!$order instanceof WC_Order) {
            return;
        }

        // Detect trigger source
        $source = $this->detectTriggerSource($fromStatus, $toStatus, $order);
        
        // Get current user if admin change
        $userId = 0;
        $userName = '';
        if ($source === 'admin' && function_exists('get_current_user_id')) {
            $userId = get_current_user_id();
            if ($userId && function_exists('get_userdata')) {
                $userData = get_userdata($userId);
                $userName = $userData ? $userData->display_name : '';
            }
        }

        // Get order note if available (WooCommerce adds notes on status change)
        $note = '';
        $notes = wc_get_order_notes([
            'order_id' => $orderId,
            'limit' => 1,
            'orderby' => 'date_created',
            'order' => 'DESC',
        ]);
        if (!empty($notes) && isset($notes[0]->content)) {
            $note = wp_strip_all_tags($notes[0]->content);
            // Truncate if too long
            if (strlen($note) > 150) {
                $note = substr($note, 0, 150) . '…';
            }
        }

        StatusLogs::add($order, [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'trigger_source' => $source,
            'user_id' => $userId,
            'user_name' => $userName,
            'note' => $note,
            'order_total' => (float) $order->get_total(),
        ]);
    }

    private function detectTriggerSource(string $fromStatus, string $toStatus, WC_Order $order): string
    {
        // Check if admin area
        if (is_admin() && function_exists('get_current_user_id') && get_current_user_id() > 0) {
            return 'admin';
        }

        // Check if gateway (Redsys deposits meta present)
        $paymentFlow = $order->get_meta('zs_deposits_payment_flow', true);
        if ($paymentFlow) {
            return 'gateway';
        }

        // Check if Flowmattic (check for flowmattic trigger in backtrace or meta)
        if (function_exists('wp_debug_backtrace_summary')) {
            $trace = wp_debug_backtrace_summary();
            if (stripos($trace, 'flowmattic') !== false) {
                return 'flowmattic';
            }
        }

        // Check for cancelled/failed
        if (in_array($toStatus, ['cancelled', 'failed'], true)) {
            return 'cancelled';
        }

        // Default: automatic (WooCommerce internal)
        return 'automatic';
    }
}
