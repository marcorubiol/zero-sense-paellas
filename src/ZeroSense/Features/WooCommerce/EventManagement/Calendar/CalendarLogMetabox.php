<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Calendar;

use WC_Order;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;

class CalendarLogMetabox
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
                'zs_calendar_sync',
                __('Google Calendar Sync', 'zero-sense'),
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

        $eventId = $order->get_meta(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, true);
        $logs = CalendarLogs::getForOrder($order);
        $noLogs = empty($logs);

        echo '<div class="zs-calendar-logs-metabox">';

        // Header: Event ID and status
        if ($eventId && is_string($eventId) && $eventId !== '') {
            echo '<div style="margin-bottom:10px; font-size:12px; color:#555;">';
            echo '<strong>' . esc_html__('Event ID:', 'zero-sense') . '</strong> ';
            echo '<code style="background:#f0f0f0;padding:2px 6px;border-radius:3px;">' . esc_html($eventId) . '</code>';
            echo '</div>';
        } else {
            echo '<div style="margin-bottom:10px; font-size:12px; color:#999; font-style:italic;">';
            echo esc_html__('No Google Calendar event linked yet.', 'zero-sense');
            echo '</div>';
        }

        // Action buttons
        echo '<div class="zs-calendar-actions" style="margin:10px 0;">';
        if ($eventId !== '') {
            // Delete button - only if event_id exists
            echo '<button type="button" class="zs-btn is-destructive zs-calendar-delete" ';
            echo 'data-order-id="' . esc_attr($orderId) . '">';
            echo '🗑️ ' . esc_html__('Delete Google Calendar Event', 'zero-sense');
            echo '</button>';
        } else {
            // Create button - only if NO event_id
            echo '<button type="button" class="zs-btn is-action zs-calendar-create" ';
            echo 'data-order-id="' . esc_attr($orderId) . '">';
            echo '➕ ' . esc_html__('Create Google Calendar Event', 'zero-sense');
            echo '</button>';
        }
        echo '</div>';

        // Last sync info
        if (!$noLogs) {
            $latest = $logs[0];
            $lastSync = esc_html($latest['timestamp'] ?? '');
            $lastType = sanitize_key($latest['type'] ?? 'unknown');
            $typeLabels = [
                'created' => __('Created', 'zero-sense'),
                'updated' => __('Updated', 'zero-sense'),
                'error' => __('Error', 'zero-sense'),
                'synced' => __('Synced', 'zero-sense'),
            ];
            $typeLabel = $typeLabels[$lastType] ?? ucfirst($lastType);
            
            echo '<div style="margin:-4px 0 10px; font-size:11px; color:#666;">';
            echo sprintf(__('Last sync: %s (%s)', 'zero-sense'), $lastSync, $typeLabel);
            echo '</div>';
        }

        // Logs list (show first 3)
        if ($noLogs) {
            echo '<div style="margin:8px 0 10px; font-size:12px; color:#777;">' . esc_html__('No sync events for this order yet.', 'zero-sense') . '</div>';
        } else {
            $count = 0; $max = 3; $hasMore = count($logs) > $max;
            
            // Show first 3 logs
            foreach ($logs as $index => $log) {
                if ($index >= $max) break;
                $this->renderLogItem($log);
            }
            
            // Show remaining logs (hidden)
            if ($hasMore) {
                echo '<div id="zs-calendar-logs-hidden" style="display:none;">';
                foreach ($logs as $index => $log) {
                    if ($index < $max) continue;
                    $this->renderLogItem($log);
                }
                echo '</div>';
                
                $remainingCount = count($logs) - $max;
                echo '<div style="text-align:center;margin-top:10px;">';
                echo '<button type="button" class="button-link zs-toggle-logs" id="zs-calendar-logs-toggle">';
                echo sprintf(esc_html__('Show %d more', 'zero-sense'), $remainingCount);
                echo '</button></div>';
                
                // Inline JS for toggle
                echo '<script>document.addEventListener("DOMContentLoaded",function(){';
                echo 'var b=document.getElementById("zs-calendar-logs-toggle");';
                echo 'if(!b) return;';
                echo 'var total=' . esc_js($remainingCount) . ';';
                echo 'b.addEventListener("click",function(){';
                echo 'var h=document.getElementById("zs-calendar-logs-hidden");';
                echo 'if(h.style.display==="none"){';
                echo 'h.style.display="block";';
                echo 'this.textContent="' . esc_js(__('Show less', 'zero-sense')) . '";';
                echo '}else{';
                echo 'h.style.display="none";';
                echo 'this.textContent="' . esc_js(__('Show', 'zero-sense')) . ' "+total+" ' . esc_js(__('more', 'zero-sense')) . '";';
                echo '}});});</script>';
            }
        }

        echo '</div>';
    }

    private function renderLogItem(array $log): void
    {
        $type = sanitize_key($log['type'] ?? 'unknown');
        $data = is_array($log['data'] ?? null) ? $log['data'] : [];
        
        // Determine badge info
        $badgeInfo = $this->getBadgeInfo($type, $data);
        $badgeClass = $badgeInfo['class'];
        $itemClass = $badgeInfo['item_class'];
        $title = $badgeInfo['title'];
        $badgeLabel = $badgeInfo['label'];

        $ts = esc_html($log['timestamp'] ?? '');
        $status = esc_html($log['status'] ?? '');
        
        // Build details
        $details = [];
        
        if (isset($data['event_id']) && is_string($data['event_id'])) {
            $details[] = '<strong>' . __('Event ID:', 'zero-sense') . '</strong> ' . esc_html($data['event_id']);
        }
        
        if (isset($data['event_title']) && is_string($data['event_title'])) {
            $details[] = '<strong>' . __('Title:', 'zero-sense') . '</strong> ' . esc_html($data['event_title']);
        }
        
        if (isset($data['action']) && is_string($data['action'])) {
            $actionLabels = [
                'title_change' => __('Title changed', 'zero-sense'),
                'date_change' => __('Date changed', 'zero-sense'),
            ];
            $actionLabel = $actionLabels[$data['action']] ?? ucfirst(str_replace('_', ' ', $data['action']));
            $details[] = '<strong>' . __('Action:', 'zero-sense') . '</strong> ' . esc_html($actionLabel);
        }
        
        if (isset($data['error']) && is_string($data['error'])) {
            $details[] = '<strong style="color:#d63638;">' . __('Error:', 'zero-sense') . '</strong> ' . esc_html($data['error']);
        }

        echo '<div class="zs-log-item ' . esc_attr($itemClass) . '">';
        echo '<div class="zs-log-title"><strong>' . esc_html($title) . '</strong></div>';
        echo '<div class="zs-log-time">' . esc_html($ts) . ' · ' . sprintf(__('Order status: %s', 'zero-sense'), $status) . '</div>';
        if ($details) {
            echo '<div class="zs-log-details">' . implode(' · ', $details) . '</div>';
        }
        echo '<span class="zs-badge ' . esc_attr($badgeClass) . '">' . esc_html($badgeLabel) . '</span>';
        echo '</div>';
    }

    private function getBadgeInfo(string $type, array $data): array
    {
        $triggerSource = $data['trigger_source'] ?? 'automatic';
        
        if ($type === 'created') {
            return [
                'class' => 'zs-badge-auto',
                'item_class' => 'zs-auto',
                'label' => __('AUTO', 'zero-sense'),
                'title' => __('Event created', 'zero-sense'),
            ];
        }
        
        if ($type === 'updated') {
            if ($triggerSource === 'manual') {
                return [
                    'class' => 'zs-badge-manual',
                    'item_class' => 'zs-manual',
                    'label' => __('MAN', 'zero-sense'),
                    'title' => __('Event updated manually', 'zero-sense'),
                ];
            }
            return [
                'class' => 'zs-badge-auto',
                'item_class' => 'zs-auto',
                'label' => __('AUTO', 'zero-sense'),
                'title' => __('Event updated', 'zero-sense'),
            ];
        }
        
        if ($type === 'error') {
            return [
                'class' => 'zs-badge-error',
                'item_class' => 'zs-error',
                'label' => __('ERROR', 'zero-sense'),
                'title' => __('Sync error', 'zero-sense'),
            ];
        }
        
        if ($type === 'synced') {
            return [
                'class' => 'zs-badge-auto',
                'item_class' => 'zs-auto',
                'label' => __('SYNCED', 'zero-sense'),
                'title' => __('Manual sync', 'zero-sense'),
            ];
        }
        
        if ($type === 'deleted') {
            return [
                'class' => 'zs-badge-manual',
                'item_class' => 'zs-manual',
                'label' => __('MAN', 'zero-sense'),
                'title' => __('Event deleted manually', 'zero-sense'),
            ];
        }
        
        // Default
        return [
            'class' => 'zs-badge-auto',
            'item_class' => 'zs-auto',
            'label' => __('AUTO', 'zero-sense'),
            'title' => __('Calendar sync', 'zero-sense'),
        ];
    }

}
