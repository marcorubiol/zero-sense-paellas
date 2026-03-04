<?php
namespace ZeroSense\Features\WooCommerce\OrderStatuses\Components;

use WC_Order;
use ZeroSense\Features\WooCommerce\OrderStatuses\Support\StatusLogs;

class StatusLogMetabox
{
    public function register(): void
    {
        if (!is_admin()) { return; }
        add_action('add_meta_boxes', [$this, 'addMetabox']);
    }

    public function addMetabox(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            $screen_id = $screen->id === 'woocommerce_page_wc-orders' ? wc_get_page_screen_id('shop-order') : 'shop_order';
            
            add_meta_box(
                'zs_order_status_logs',
                __('Order Status Logs', 'zero-sense'),
                [$this, 'renderMetabox'],
                $screen_id,
                'normal',
                'default'
            );
        }
    }

    public function renderMetabox($postOrOrder): void
    {
        $orderId = 0;
        if ($postOrOrder instanceof \WP_Post) {
            $orderId = $postOrOrder->ID;
        } elseif ($postOrOrder instanceof WC_Order) {
            $orderId = $postOrOrder->get_id();
        }
        
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            echo '<p>' . esc_html__('Invalid order.', 'zero-sense') . '</p>';
            return;
        }

        $logs = StatusLogs::getForOrder($order);
        $noLogs = empty($logs);

        echo '<div class="zs-status-logs-metabox">';

        // Header
        echo '<div style="margin-bottom:10px; font-size:12px; color:#555;">';
        echo esc_html(sprintf(__('Total transitions: %d', 'zero-sense'), count($logs)));
        echo '</div>';

        // Logs list (show first 3)
        if ($noLogs) {
            echo '<div style="margin:8px 0 0; font-size:12px; color:#777;">' . esc_html__('No status changes recorded yet.', 'zero-sense') . '</div>';
        } else {
            $count = 0; $max = 3; $hasMore = count($logs) > $max;
            echo '<div id="zs-status-logs-list">';
            foreach ($logs as $log) {
                $count++;
                $hidden = ($count > $max) ? ' style="display:none" class="zs-hidden"' : '';
                
                $source = sanitize_key($log['trigger_source'] ?? 'unknown');
                $badgeClass = 'zs-badge-unknown';
                $itemClass = 'zs-unknown';
                $badgeLabelMap = [
                    'automatic' => __('AUTO', 'zero-sense'),
                    'admin' => __('MAN', 'zero-sense'),
                    'manual' => __('MAN', 'zero-sense'),
                    'gateway' => __('GATEWAY', 'zero-sense'),
                    'flowmattic' => __('FLOWMATTIC', 'zero-sense'),
                    'cancelled' => __('CANCELLED', 'zero-sense'),
                    'failed' => __('FAILED', 'zero-sense'),
                    'unknown' => __('OTHER', 'zero-sense'),
                ];

                if ($source === 'admin') {
                    $badgeClass = 'zs-badge-manual';
                    $itemClass = 'zs-manual';
                } elseif ($source === 'gateway') {
                    $badgeClass = 'zs-badge-gateway';
                    $itemClass = 'zs-gateway';
                } elseif ($source === 'flowmattic') {
                    $badgeClass = 'zs-badge-flowmattic';
                    $itemClass = 'zs-flowmattic';
                } elseif ($source === 'automatic') {
                    $badgeClass = 'zs-badge-automatic';
                    $itemClass = 'zs-automatic';
                } elseif (in_array($source, ['cancelled', 'failed'], true)) {
                    $badgeClass = 'zs-badge-cancelled';
                    $itemClass = 'zs-cancelled';
                }

                $badgeLabel = $badgeLabelMap[$source] ?? ($source ? strtoupper($source) : __('OTHER', 'zero-sense'));

                $from = esc_html($log['from_status'] ?? '—');
                $to = esc_html($log['to_status'] ?? '—');
                $ts = esc_html($log['timestamp'] ?? '');
                $userName = esc_html($log['user_name'] ?? '');
                $note = esc_html($log['note'] ?? '');
                $total = isset($log['order_total']) ? wc_price((float) $log['order_total'], ['currency' => $order->get_currency()]) : '';

                echo '<div' . $hidden . ' class="zs-log-item ' . esc_attr($itemClass) . '">';
                echo '<div class="zs-log-title"><strong>' . sprintf(__('From %s → %s', 'zero-sense'), $from, $to) . '</strong></div>';
                echo '<div class="zs-log-time">' . esc_html($ts);
                if ($userName) {
                    echo ' · ' . sprintf(__('By: %s', 'zero-sense'), $userName);
                }
                echo '</div>';
                
                $details = [];
                if ($note) {
                    $details[] = '<strong>' . __('Note:', 'zero-sense') . '</strong> ' . $note;
                }
                if ($total) {
                    $details[] = '<strong>' . __('Total:', 'zero-sense') . '</strong> ' . wp_strip_all_tags($total);
                }
                
                if ($details) {
                    echo '<div class="zs-log-details">' . implode(' · ', $details) . '</div>';
                }
                
                echo '<span class="zs-badge ' . esc_attr($badgeClass) . '">' . esc_html($badgeLabel) . '</span>';
                
                // Delete button
                echo '<button type="button" class="zs-log-delete" ';
                echo 'data-log-type="status" ';
                echo 'data-order-id="' . esc_attr($orderId) . '" ';
                echo 'data-timestamp="' . esc_attr($log['timestamp'] ?? '') . '" ';
                echo 'title="' . esc_attr__('Delete this log', 'zero-sense') . '">';
                echo '×';
                echo '</button>';
                
                echo '</div>';
            }
            echo '</div>';

            if ($hasMore) {
                $remainingCount = count($logs) - $max;
                echo '<div style="text-align:center;margin-top:10px;"><button type="button" class="button-link" id="zs-status-logs-toggle">' . sprintf(esc_html__('Show %d more', 'zero-sense'), $remainingCount) . '</button></div>';
                echo '<script>document.addEventListener("DOMContentLoaded",function(){var b=document.getElementById("zs-status-logs-toggle");if(!b) return;var total=' . esc_js($remainingCount) . ';b.addEventListener("click",function(){var h=document.querySelectorAll("#zs-status-logs-list .zs-hidden");h.forEach(function(el){el.style.display=el.style.display==="none"?"block":"none"});this.textContent=this.textContent.includes("Show")?"' . esc_js(__('Show less', 'zero-sense')) . '":"' . esc_js(__('Show', 'zero-sense')) . ' "+total+" ' . esc_js(__('more', 'zero-sense')) . '";});});</script>';
            }
        }

        echo '</div>';
    }
}
