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
        
        // AJAX handlers
        add_action('wp_ajax_zs_calendar_create_event', [$this, 'ajaxCreateEvent']);
        add_action('wp_ajax_zs_calendar_delete_event', [$this, 'ajaxDeleteEvent']);
        add_action('wp_ajax_zs_calendar_check_status', [$this, 'ajaxCheckStatus']);
        add_action('wp_ajax_zs_calendar_get_header', [$this, 'ajaxGetHeader']);
        add_action('wp_ajax_zs_calendar_update_event', [$this, 'ajaxUpdateEvent']);
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

        wp_nonce_field('zs_calendar_action', 'zs_calendar_nonce');
        
        echo '<div class="zs-calendar-logs-metabox">';

        // Header section (wrapped for AJAX replacement)
        echo '<div class="zs-calendar-header-section">';
        
        // Event ID display
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
        $isReserved = $order->get_meta(MetaKeys::EVENT_RESERVED, true) === 'yes';
        echo '<div class="zs-calendar-actions" style="margin:10px 0; display:flex; gap:8px; flex-wrap:wrap;">';
        if ($eventId !== '') {
            if (!$isReserved) {
                // Update button - only if event_id exists and NOT reserved
                echo '<button type="button" class="zs-btn is-action zs-calendar-action-btn" ';
                echo 'data-action="update" data-order-id="' . esc_attr($orderId) . '">';
                echo '<span class="zs-calendar-btn-label">' . esc_html__('Reserve Event', 'zero-sense') . '</span>';
                echo '</button>';
            }
            
            // Delete button - only if event_id exists
            echo '<button type="button" class="zs-btn is-destructive zs-calendar-action-btn" ';
            echo 'data-action="delete" data-order-id="' . esc_attr($orderId) . '">';
            echo '<span class="zs-calendar-btn-label">' . esc_html__('Delete Google Calendar Event', 'zero-sense') . '</span>';
            echo '</button>';
        } else {
            // Create button - only if NO event_id
            echo '<button type="button" class="zs-btn is-action zs-calendar-action-btn" ';
            echo 'data-action="create" data-order-id="' . esc_attr($orderId) . '">';
            echo '<span class="zs-calendar-btn-label">' . esc_html__('Create Google Calendar Event', 'zero-sense') . '</span>';
            echo '</button>';
        }
        echo '</div>';
        
        echo '</div>'; // .zs-calendar-header-section

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
        
        // Add inline JavaScript for AJAX handling
        $this->renderInlineScript($orderId);
    }
    
    private function renderInlineScript(int $orderId): void
    {
        $nonce = wp_create_nonce('zs_calendar_action');
        
        // Force cache bust
        echo '<!-- ZS Calendar v2.0 -->';
        ?>
        <script>
        (function() {
            function attachButtonListeners() {
                document.querySelectorAll('.zs-calendar-action-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                    if (this.disabled) return;

                    var action = this.getAttribute('data-action');
                    var orderId = this.getAttribute('data-order-id');
                    var labelEl = this.querySelector('.zs-calendar-btn-label');
                    var originalText = labelEl.textContent;
                    var btn = this;
                    
                    var msgs = {
                        'delete': ['Delete Google Calendar event? This cannot be undone.', 'Deleting...'],
                        'update': ['Mark event as reserved?', 'Updating...'],
                        'create': ['Create Google Calendar event for this order?', 'Creating...']
                    };
                    
                    if (!confirm(msgs[action][0])) return;
                    
                    labelEl.textContent = msgs[action][1];
                    
                    // Trigger workflow via AJAX
                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'zs_calendar_' + action + '_event',
                            order_id: orderId,
                            nonce: <?php echo wp_json_encode($nonce); ?>
                        })
                    })
                    .then(r => r.json())
                    .then(data => {
                        // Start polling for changes
                        let attempts = 0;
                        const maxAttempts = 10;
                        
                        const poll = () => {
                            fetch(ajaxurl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: new URLSearchParams({
                                    action: 'zs_calendar_check_status',
                                    order_id: orderId,
                                    check_action: action,
                                    nonce: <?php echo wp_json_encode($nonce); ?>
                                })
                            })
                            .then(r => r.json())
                            .then(res => {
                                if (res.success && res.data && res.data.changed) {
                                    // Status changed, refresh header
                                    fetch(ajaxurl, {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                        body: new URLSearchParams({
                                            action: 'zs_calendar_get_header',
                                            order_id: orderId,
                                            nonce: <?php echo wp_json_encode($nonce); ?>
                                        })
                                    })
                                    .then(r => r.json())
                                    .then(headerRes => {
                                        if (headerRes.success && headerRes.data && headerRes.data.html) {
                                            // Replace header + buttons
                                            const container = document.querySelector('.zs-calendar-logs-metabox');
                                            if (container) {
                                                const temp = document.createElement('div');
                                                temp.innerHTML = headerRes.data.html;
                                                const oldHeader = container.querySelector('.zs-calendar-header-section');
                                                if (oldHeader && temp.firstElementChild) {
                                                    oldHeader.replaceWith(temp.firstElementChild);
                                                }
                                            }
                                        }
                                        // Re-attach event listeners to new buttons
                                        attachButtonListeners();
                                    });
                                } else if (attempts < maxAttempts) {
                                    attempts++;
                                    setTimeout(poll, 1000);
                                } else {
                                    btn.disabled = false;
                                    labelEl.textContent = originalText;
                                }
                            })
                            .catch(err => {
                                attempts++;
                                if (attempts < maxAttempts) {
                                    setTimeout(poll, 1000);
                                } else {
                                    btn.disabled = false;
                                    labelEl.textContent = originalText;
                                }
                            });
                        };
                        
                        setTimeout(poll, 1000);
                    })
                    .catch(err => {
                        btn.disabled = false;
                        labelEl.textContent = originalText;
                        alert('Error: ' + err.message);
                    });
                });
            }
            
            // Initialize on page load
            document.addEventListener('DOMContentLoaded', attachButtonListeners);
        })();
        </script>
        <?php
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
            if ($triggerSource === 'manual') {
                return [
                    'class' => 'zs-badge-manual',
                    'item_class' => 'zs-manual',
                    'label' => __('MAN', 'zero-sense'),
                    'title' => __('Google Calendar Event Created', 'zero-sense'),
                ];
            }
            return [
                'class' => 'zs-badge-auto',
                'item_class' => 'zs-auto',
                'label' => __('AUTO', 'zero-sense'),
                'title' => __('Google Calendar Event Created', 'zero-sense'),
            ];
        }
        
        if ($type === 'deleted') {
            if ($triggerSource === 'manual') {
                return [
                    'class' => 'zs-badge-manual',
                    'item_class' => 'zs-manual',
                    'label' => __('MAN', 'zero-sense'),
                    'title' => __('Google Calendar Event Deleted', 'zero-sense'),
                ];
            }
            return [
                'class' => 'zs-badge-auto',
                'item_class' => 'zs-auto',
                'label' => __('AUTO', 'zero-sense'),
                'title' => __('Google Calendar Event Deleted', 'zero-sense'),
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
        
        // Default
        return [
            'class' => 'zs-badge-auto',
            'item_class' => 'zs-auto',
            'label' => __('AUTO', 'zero-sense'),
            'title' => __('Calendar sync', 'zero-sense'),
        ];
    }

    /**
     * AJAX handler to create calendar event
     */
    public function ajaxCreateEvent(): void
    {
        check_ajax_referer('zs_calendar_action', 'nonce');
        
        $orderId = absint($_POST['order_id'] ?? 0);
        if ($orderId === 0) {
            wp_send_json_error('Invalid order ID');
        }
        
        // Trigger FlowMattic workflow via class action
        do_action('zs_trigger_class_action_direct', 'zs-calendar-create', $orderId);
        
        wp_send_json_success(['message' => 'Workflow triggered']);
    }

    /**
     * AJAX handler to delete calendar event
     */
    public function ajaxDeleteEvent(): void
    {
        check_ajax_referer('zs_calendar_action', 'nonce');
        
        $orderId = absint($_POST['order_id'] ?? 0);
        if ($orderId === 0) {
            wp_send_json_error('Invalid order ID');
        }
        
        // Trigger FlowMattic workflow via class action
        do_action('zs_trigger_class_action_direct', 'zs-calendar-delete', $orderId);
        
        wp_send_json_success(['message' => 'Workflow triggered']);
    }

    /**
     * AJAX handler to update calendar event
     */
    public function ajaxUpdateEvent(): void
    {
        check_ajax_referer('zs_calendar_action', 'nonce');
        
        $orderId = absint($_POST['order_id'] ?? 0);
        if ($orderId === 0) {
            wp_send_json_error('Invalid order ID');
        }
        
        // Trigger FlowMattic workflow via class action with 'manual' trigger source
        do_action('zs_trigger_class_action_direct', 'zs-calendar-update', $orderId, 'manual');
        
        wp_send_json_success(['message' => 'Workflow triggered']);
    }

    /**
     * AJAX handler to check if event status changed
     */
    public function ajaxCheckStatus(): void
    {
        check_ajax_referer('zs_calendar_action', 'nonce');
        
        $orderId = absint($_POST['order_id'] ?? 0);
        $checkAction = sanitize_text_field($_POST['check_action'] ?? '');
        
        $order = wc_get_order($orderId);
        if (!$order) {
            wp_send_json_error('Order not found');
        }
        
        $eventId = $order->get_meta(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, true);
        
        $changed = false;
        if ($checkAction === 'create' && $eventId !== '') {
            $changed = true; // Event ID now exists
        } elseif ($checkAction === 'delete' && $eventId === '') {
            $changed = true; // Event ID was deleted
        } elseif ($checkAction === 'update') {
            // Check if zs_event_reserved changed to 'yes'
            $isReserved = $order->get_meta(MetaKeys::EVENT_RESERVED, true) === 'yes';
            $changed = $isReserved;
        }
        
        wp_send_json_success(['changed' => $changed]);
    }

    /**
     * AJAX handler to get updated header HTML
     */
    public function ajaxGetHeader(): void
    {
        check_ajax_referer('zs_calendar_action', 'nonce');
        
        $orderId = absint($_POST['order_id'] ?? 0);
        $order = wc_get_order($orderId);
        
        if (!$order) {
            wp_send_json_error('Order not found');
        }
        
        $eventId = $order->get_meta(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, true);
        
        // Render header HTML
        ob_start();
        echo '<div class="zs-calendar-header-section">';
        
        // Event ID display
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
        $isReserved = $order->get_meta(MetaKeys::EVENT_RESERVED, true) === 'yes';
        echo '<div class="zs-calendar-actions" style="margin:10px 0; display:flex; gap:8px; flex-wrap:wrap;">';
        if ($eventId !== '') {
            if (!$isReserved) {
                echo '<button type="button" class="zs-btn is-action zs-calendar-action-btn" ';
                echo 'data-action="update" data-order-id="' . esc_attr($orderId) . '">';
                echo '<span class="zs-calendar-btn-label">' . esc_html__('Reserve Event', 'zero-sense') . '</span>';
                echo '</button>';
            }
            echo '<button type="button" class="zs-btn is-destructive zs-calendar-action-btn" ';
            echo 'data-action="delete" data-order-id="' . esc_attr($orderId) . '">';
            echo '<span class="zs-calendar-btn-label">' . esc_html__('Delete Google Calendar Event', 'zero-sense') . '</span>';
            echo '</button>';
        } else {
            echo '<button type="button" class="zs-btn is-action zs-calendar-action-btn" ';
            echo 'data-action="create" data-order-id="' . esc_attr($orderId) . '">';
            echo '<span class="zs-calendar-btn-label">' . esc_html__('Create Google Calendar Event', 'zero-sense') . '</span>';
            echo '</button>';
        }
        echo '</div>';

        
        echo '</div>';
        
        $html = ob_get_clean();
        wp_send_json_success(['html' => $html]);
    }

}
