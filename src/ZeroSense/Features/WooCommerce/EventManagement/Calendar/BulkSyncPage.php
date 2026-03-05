<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Calendar;

use ZeroSense\Core\FeatureInterface;
use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;

class BulkSyncPage implements FeatureInterface
{
    public function getName(): string
    {
        return __('Calendar Bulk Operations', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Provides bulk create and delete operations for Google Calendar events. Allows mass syncing of orders to calendar.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'Utilities';
    }

    public function isToggleable(): bool
    {
        return true;
    }

    public function isEnabled(): bool
    {
        return (bool) get_option($this->getOptionName(), true);
    }

    public function getOptionName(): string
    {
        return 'zs_utilities_calendar_bulk_operations_enable';
    }

    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        add_action('admin_menu', [$this, 'addAdminPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        
        // AJAX endpoints
        add_action('wp_ajax_zs_bulk_get_queue', [$this, 'ajaxGetQueue']);
        add_action('wp_ajax_zs_bulk_process_one', [$this, 'ajaxProcessOne']);
        add_action('wp_ajax_zs_bulk_cleanup_one', [$this, 'ajaxCleanupOne']);
        add_action('wp_ajax_zs_bulk_get_stats', [$this, 'ajaxGetStats']);
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getConditions(): array
    {
        return ['is_admin', 'class_exists:WooCommerce'];
    }

    public function addAdminPage(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Bulk Calendar Sync', 'zero-sense'),
            __('Calendar Bulk Sync', 'zero-sense'),
            'manage_woocommerce',
            'zs-calendar-bulk-sync',
            [$this, 'renderPage']
        );
    }

    public function enqueueAssets($hook): void
    {
        if ($hook !== 'woocommerce_page_zs-calendar-bulk-sync') {
            return;
        }

        wp_enqueue_script(
            'zs-bulk-sync',
            ZERO_SENSE_URL . 'assets/js/bulk-sync.js',
            ['jquery'],
            ZERO_SENSE_VERSION,
            true
        );

        wp_enqueue_style(
            'zs-bulk-sync',
            ZERO_SENSE_URL . 'assets/css/bulk-sync.css',
            [],
            ZERO_SENSE_VERSION
        );

        wp_localize_script('zs-bulk-sync', 'zsBulkSync', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('zs_bulk_sync'),
        ]);
    }

    public function renderPage(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bulk Calendar Operations', 'zero-sense'); ?></h1>
            
            <!-- STATISTICS -->
            <div class="card zs-bulk-section">
                <h2><?php esc_html_e('📊 Event Statistics', 'zero-sense'); ?></h2>
                <p><?php esc_html_e('Check how many orders have Google Calendar events created.', 'zero-sense'); ?></p>
                
                <div class="zs-status-selector">
                    <label><input type="checkbox" name="stats_statuses[]" value="pending" checked> <?php esc_html_e('Pending', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="stats_statuses[]" value="deposit-paid" checked> <?php esc_html_e('Deposit Paid', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="stats_statuses[]" value="fully-paid" checked> <?php esc_html_e('Fully Paid', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="stats_statuses[]" value="processing" checked> <?php esc_html_e('Processing', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="stats_statuses[]" value="completed" checked> <?php esc_html_e('Completed', 'zero-sense'); ?></label>
                </div>
                
                <div class="zs-bulk-controls">
                    <button type="button" id="zs-stats-check" class="button button-secondary button-large">
                        <?php esc_html_e('Check Statistics', 'zero-sense'); ?>
                    </button>
                </div>
                
                <div id="zs-stats-results" style="display:none; margin-top: 20px;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                        <div class="zs-stat-card" style="background: #f0f0f1; padding: 20px; border-radius: 4px; text-align: center;">
                            <div style="font-size: 32px; font-weight: bold; color: #2271b1;" id="zs-stat-total">-</div>
                            <div style="color: #646970; margin-top: 5px;"><?php esc_html_e('Total Orders', 'zero-sense'); ?></div>
                        </div>
                        <div class="zs-stat-card" style="background: #f0f0f1; padding: 20px; border-radius: 4px; text-align: center; cursor: pointer;" id="zs-stat-card-with">
                            <div style="font-size: 32px; font-weight: bold; color: #00a32a;" id="zs-stat-with-event">-</div>
                            <div style="color: #646970; margin-top: 5px;"><?php esc_html_e('With Event', 'zero-sense'); ?> 👁️</div>
                        </div>
                        <div class="zs-stat-card" style="background: #f0f0f1; padding: 20px; border-radius: 4px; text-align: center; cursor: pointer;" id="zs-stat-card-without">
                            <div style="font-size: 32px; font-weight: bold; color: #d63638;" id="zs-stat-without-event">-</div>
                            <div style="color: #646970; margin-top: 5px;"><?php esc_html_e('Without Event', 'zero-sense'); ?> 👁️</div>
                        </div>
                        <div class="zs-stat-card" style="background: #f0f0f1; padding: 20px; border-radius: 4px; text-align: center;">
                            <div style="font-size: 32px; font-weight: bold; color: #2271b1;" id="zs-stat-percentage">-</div>
                            <div style="color: #646970; margin-top: 5px;"><?php esc_html_e('Coverage', 'zero-sense'); ?></div>
                        </div>
                    </div>
                    
                    <div id="zs-stats-orders" style="display:none; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 15px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h3 style="margin: 0;" id="zs-stats-orders-title"></h3>
                            <button type="button" id="zs-stats-orders-close" class="button button-small">✕</button>
                        </div>
                        <div id="zs-stats-orders-list" style="max-height: 300px; overflow-y: auto;"></div>
                    </div>
                </div>
            </div>
            
            <!-- CREATE EVENTS -->
            <div class="card zs-bulk-section">
                <h2><?php esc_html_e('Create Events', 'zero-sense'); ?></h2>
                <p><?php esc_html_e('This will update each order (triggering automatic validation), then create Google Calendar events for all eligible orders and automatically reserve paid orders.', 'zero-sense'); ?></p>
                
                <div class="zs-status-selector">
                    <label><input type="checkbox" name="create_statuses[]" value="pending" checked> <?php esc_html_e('Pending', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="create_statuses[]" value="deposit-paid" checked> <?php esc_html_e('Deposit Paid', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="create_statuses[]" value="fully-paid" checked> <?php esc_html_e('Fully Paid', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="create_statuses[]" value="processing" checked> <?php esc_html_e('Processing', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="create_statuses[]" value="completed" checked> <?php esc_html_e('Completed', 'zero-sense'); ?></label>
                </div>
                
                <div class="zs-bulk-controls">
                    <button type="button" id="zs-create-start" class="button button-primary button-large">
                        <?php esc_html_e('Start Creating Events', 'zero-sense'); ?>
                    </button>
                    <button type="button" id="zs-create-pause" class="button button-large" style="display:none;">
                        <?php esc_html_e('Pause', 'zero-sense'); ?>
                    </button>
                    <button type="button" id="zs-create-cancel" class="button button-large" style="display:none;">
                        <?php esc_html_e('Cancel', 'zero-sense'); ?>
                    </button>
                    
                    <div class="zs-bulk-speed">
                        <label><?php esc_html_e('Delay:', 'zero-sense'); ?></label>
                        <select id="zs-create-delay">
                            <option value="2">2s</option>
                            <option value="3" selected>3s</option>
                            <option value="5">5s</option>
                            <option value="10">10s</option>
                        </select>
                    </div>
                </div>
                
                <div class="zs-bulk-progress" style="display:none;">
                    <div class="zs-progress-bar">
                        <div class="zs-progress-fill" style="width: 0%;"></div>
                    </div>
                    <div class="zs-progress-text">0% (0/0 orders)</div>
                    <div class="zs-progress-eta"></div>
                </div>
                
                <div class="zs-bulk-stats" style="display:none;">
                    <span class="zs-stat"><strong><?php esc_html_e('Created:', 'zero-sense'); ?></strong> <span id="zs-create-count-created">0</span></span>
                    <span class="zs-stat"><strong><?php esc_html_e('Reserved:', 'zero-sense'); ?></strong> <span id="zs-create-count-reserved">0</span></span>
                    <span class="zs-stat"><strong><?php esc_html_e('Skipped:', 'zero-sense'); ?></strong> <span id="zs-create-count-skipped">0</span></span>
                    <span class="zs-stat"><strong><?php esc_html_e('Errors:', 'zero-sense'); ?></strong> <span id="zs-create-count-errors">0</span></span>
                </div>
                
                <div class="zs-bulk-log" id="zs-create-log" style="display:none;"></div>
            </div>
            
            <!-- CLEANUP EVENT IDS -->
            <div class="card zs-bulk-section">
                <h2 style="color: #d63638;"><?php esc_html_e('⚠️ Cleanup Event IDs (Dangerous)', 'zero-sense'); ?></h2>
                <p style="color: #d63638;"><strong><?php esc_html_e('WARNING: This will remove all Google Calendar Event IDs from orders. Use only if you need to start fresh!', 'zero-sense'); ?></strong></p>
                
                <div class="zs-status-selector">
                    <label><input type="checkbox" name="cleanup_statuses[]" value="pending" checked> <?php esc_html_e('Pending', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="cleanup_statuses[]" value="deposit-paid" checked> <?php esc_html_e('Deposit Paid', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="cleanup_statuses[]" value="fully-paid" checked> <?php esc_html_e('Fully Paid', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="cleanup_statuses[]" value="processing" checked> <?php esc_html_e('Processing', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="cleanup_statuses[]" value="completed" checked> <?php esc_html_e('Completed', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="cleanup_statuses[]" value="cancelled" checked> <?php esc_html_e('Cancelled', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="cleanup_statuses[]" value="failed" checked> <?php esc_html_e('Failed', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="cleanup_statuses[]" value="refunded" checked> <?php esc_html_e('Refunded', 'zero-sense'); ?></label>
                </div>
                
                <div class="zs-bulk-controls">
                    <button type="button" id="zs-cleanup-start" class="button button-large" style="background: #d63638; border-color: #d63638; color: #fff;">
                        <?php esc_html_e('Start Cleanup (Remove Event IDs)', 'zero-sense'); ?>
                    </button>
                    <button type="button" id="zs-cleanup-pause" class="button button-large" style="display:none;">
                        <?php esc_html_e('Pause', 'zero-sense'); ?>
                    </button>
                    <button type="button" id="zs-cleanup-cancel" class="button button-large" style="display:none;">
                        <?php esc_html_e('Cancel', 'zero-sense'); ?>
                    </button>
                    
                    <div class="zs-bulk-speed">
                        <label><?php esc_html_e('Delay:', 'zero-sense'); ?></label>
                        <select id="zs-cleanup-delay">
                            <option value="0">0s (fast)</option>
                            <option value="1" selected>1s</option>
                            <option value="2">2s</option>
                        </select>
                    </div>
                </div>
                
                <div class="zs-bulk-progress" style="display:none;">
                    <div class="zs-progress-bar">
                        <div class="zs-progress-fill" style="width: 0%;"></div>
                    </div>
                    <div class="zs-progress-text">0% (0/0 orders)</div>
                    <div class="zs-progress-eta"></div>
                </div>
                
                <div class="zs-bulk-stats" style="display:none;">
                    <span class="zs-stat"><strong><?php esc_html_e('Cleaned:', 'zero-sense'); ?></strong> <span id="zs-cleanup-count-cleaned">0</span></span>
                    <span class="zs-stat"><strong><?php esc_html_e('Skipped:', 'zero-sense'); ?></strong> <span id="zs-cleanup-count-skipped">0</span></span>
                    <span class="zs-stat"><strong><?php esc_html_e('Errors:', 'zero-sense'); ?></strong> <span id="zs-cleanup-count-errors">0</span></span>
                </div>
                
                <div class="zs-bulk-log" id="zs-cleanup-log" style="display:none;"></div>
            </div>
            
            <!-- DELETE EVENTS -->
            <div class="card zs-bulk-section">
                <h2 style="color: #d63638;"><?php esc_html_e('Delete Calendar Events', 'zero-sense'); ?></h2>
                <p><?php esc_html_e('Select which order statuses you want to delete calendar events for:', 'zero-sense'); ?></p>
                
                <div class="zs-status-selector">
                    <label><input type="checkbox" name="delete_statuses[]" value="pending" checked> <?php esc_html_e('Pending', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="delete_statuses[]" value="deposit-paid" checked> <?php esc_html_e('Deposit Paid', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="delete_statuses[]" value="fully-paid" checked> <?php esc_html_e('Fully Paid', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="delete_statuses[]" value="processing" checked> <?php esc_html_e('Processing', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="delete_statuses[]" value="completed" checked> <?php esc_html_e('Completed', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="delete_statuses[]" value="cancelled" checked> <?php esc_html_e('Cancelled', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="delete_statuses[]" value="failed" checked> <?php esc_html_e('Failed', 'zero-sense'); ?></label>
                    <label><input type="checkbox" name="delete_statuses[]" value="refunded" checked> <?php esc_html_e('Refunded', 'zero-sense'); ?></label>
                </div>
                
                <div class="zs-bulk-controls">
                    <button type="button" id="zs-delete-start" class="button button-large" style="background: #d63638; border-color: #d63638; color: #fff;">
                        <?php esc_html_e('Start Deleting Events', 'zero-sense'); ?>
                    </button>
                    <button type="button" id="zs-delete-pause" class="button button-large" style="display:none;">
                        <?php esc_html_e('Pause', 'zero-sense'); ?>
                    </button>
                    <button type="button" id="zs-delete-cancel" class="button button-large" style="display:none;">
                        <?php esc_html_e('Cancel', 'zero-sense'); ?>
                    </button>
                    
                    <div class="zs-bulk-speed">
                        <label><?php esc_html_e('Delay:', 'zero-sense'); ?></label>
                        <select id="zs-delete-delay">
                            <option value="2">2s</option>
                            <option value="3" selected>3s</option>
                            <option value="5">5s</option>
                            <option value="10">10s</option>
                        </select>
                    </div>
                </div>
                
                <div class="zs-bulk-progress" style="display:none;">
                    <div class="zs-progress-bar">
                        <div class="zs-progress-fill" style="width: 0%;"></div>
                    </div>
                    <div class="zs-progress-text">0% (0/0 orders)</div>
                    <div class="zs-progress-eta"></div>
                </div>
                
                <div class="zs-bulk-stats" style="display:none;">
                    <span class="zs-stat"><strong><?php esc_html_e('Deleted:', 'zero-sense'); ?></strong> <span id="zs-delete-count-deleted">0</span></span>
                    <span class="zs-stat"><strong><?php esc_html_e('Skipped:', 'zero-sense'); ?></strong> <span id="zs-delete-count-skipped">0</span></span>
                    <span class="zs-stat"><strong><?php esc_html_e('Errors:', 'zero-sense'); ?></strong> <span id="zs-delete-count-errors">0</span></span>
                </div>
                
                <div class="zs-bulk-log" id="zs-delete-log" style="display:none;"></div>
            </div>
        </div>
        <?php
    }

    public function ajaxGetQueue(): void
    {
        check_ajax_referer('zs_bulk_sync', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }

        $operation = sanitize_text_field($_POST['operation'] ?? 'create');
        $statuses = isset($_POST['statuses']) && is_array($_POST['statuses']) 
            ? array_map('sanitize_text_field', $_POST['statuses']) 
            : [];
        
        if (empty($statuses)) {
            wp_send_json_error('No statuses selected');
        }
        
        if ($operation === 'delete') {
            $args = [
                'limit' => 500,
                'status' => $statuses,
                'return' => 'ids',
                'meta_query' => [
                    [
                        'key' => MetaKeys::GOOGLE_CALENDAR_EVENT_ID,
                        'compare' => 'EXISTS',
                    ],
                ],
            ];
        } elseif ($operation === 'cleanup') {
            $args = [
                'limit' => 500,
                'status' => $statuses,
                'return' => 'ids',
                'meta_query' => [
                    [
                        'key' => MetaKeys::GOOGLE_CALENDAR_EVENT_ID,
                        'compare' => 'EXISTS',
                    ],
                ],
            ];
        } else {
            // create operation
            $args = [
                'limit' => 500,
                'status' => $statuses,
                'return' => 'ids',
                'meta_query' => [
                    [
                        'key' => MetaKeys::EVENT_DATE,
                        'compare' => 'EXISTS',
                    ],
                ],
            ];
        }
        
        $orderIds = wc_get_orders($args);
        
        wp_send_json_success([
            'queue' => $orderIds,
            'total' => count($orderIds),
        ]);
    }

    public function ajaxCleanupOne(): void
    {
        check_ajax_referer('zs_bulk_sync', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }

        $orderId = absint($_POST['order_id'] ?? 0);
        if (!$orderId) {
            wp_send_json_error('Invalid order ID');
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            wp_send_json_error('Order not found');
        }

        $eventId = $order->get_meta(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, true);
        
        if ($eventId && $eventId !== '') {
            $order->delete_meta_data(MetaKeys::GOOGLE_CALENDAR_EVENT_ID);
            $order->delete_meta_data('zs_google_calendar_id');
            $order->delete_meta_data(MetaKeys::EVENT_RESERVED);
            $order->save_meta_data();
            
            wp_send_json_success([
                'message' => sprintf(__('Order #%d: Cleaned up (removed event ID: %s)', 'zero-sense'), $orderId, $eventId),
                'action' => 'cleaned',
            ]);
        } else {
            wp_send_json_success([
                'message' => sprintf(__('Order #%d: No cleanup needed', 'zero-sense'), $orderId),
                'action' => 'skipped',
            ]);
        }
    }

    public function ajaxGetStats(): void
    {
        check_ajax_referer('zs_bulk_sync', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }

        $statuses = isset($_POST['statuses']) && is_array($_POST['statuses']) 
            ? array_map('sanitize_text_field', $_POST['statuses']) 
            : ['pending', 'deposit-paid', 'fully-paid', 'processing', 'completed'];
        
        if (empty($statuses)) {
            wp_send_json_error('No statuses selected');
        }

        // Get all orders with event date
        $allOrdersArgs = [
            'limit' => -1,
            'status' => $statuses,
            'return' => 'ids',
            'meta_query' => [
                [
                    'key' => MetaKeys::EVENT_DATE,
                    'compare' => 'EXISTS',
                ],
            ],
        ];
        
        $allOrderIds = wc_get_orders($allOrdersArgs);
        $totalOrders = count($allOrderIds);
        
        // Get orders with event ID
        $withEventArgs = [
            'limit' => -1,
            'status' => $statuses,
            'return' => 'ids',
            'meta_query' => [
                [
                    'key' => MetaKeys::EVENT_DATE,
                    'compare' => 'EXISTS',
                ],
                [
                    'key' => MetaKeys::GOOGLE_CALENDAR_EVENT_ID,
                    'compare' => 'EXISTS',
                ],
            ],
        ];
        
        $withEventIds = wc_get_orders($withEventArgs);
        $withEvent = count($withEventIds);
        
        // Calculate orders without event (difference between all and with event)
        $withoutEventIds = array_diff($allOrderIds, $withEventIds);
        $withoutEvent = count($withoutEventIds);
        $percentage = $totalOrders > 0 ? round(($withEvent / $totalOrders) * 100, 1) : 0;
        
        wp_send_json_success([
            'total' => $totalOrders,
            'with_event' => $withEvent,
            'without_event' => $withoutEvent,
            'percentage' => $percentage,
            'with_event_ids' => array_values($withEventIds),
            'without_event_ids' => array_values($withoutEventIds),
        ]);
    }

    public function ajaxProcessOne(): void
    {
        check_ajax_referer('zs_bulk_sync', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }

        $orderId = absint($_POST['order_id'] ?? 0);
        $operation = sanitize_text_field($_POST['operation'] ?? 'create');

        if (!$orderId) {
            wp_send_json_error('Invalid order ID');
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            wp_send_json_error('Order not found');
        }

        if ($operation === 'delete') {
            $this->processDelete($order);
        } else {
            $this->processCreate($order);
        }
    }

    private function processCreate($order): void
    {
        $orderId = $order->get_id();
        $eventId = $order->get_meta(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, true);
        
        if ($eventId && $eventId !== '') {
            wp_send_json_success([
                'message' => sprintf(__('Order #%d: Skipped (already has event ID)', 'zero-sense'), $orderId),
                'action' => 'skipped',
            ]);
            return;
        }
        
        $eventDate = $order->get_meta(MetaKeys::EVENT_DATE, true);
        if (!$eventDate) {
            wp_send_json_success([
                'message' => sprintf(__('Order #%d: Skipped (no event date)', 'zero-sense'), $orderId),
                'action' => 'skipped',
            ]);
            return;
        }
        
        // Force order save to trigger any automatic validation/migration hooks
        $order->save();
        
        $orderStatus = $order->get_status();
        $shouldReserve = in_array($orderStatus, ['deposit-paid', 'fully-paid', 'completed'], true);
        
        if (class_exists('\ZeroSense\Features\WooCommerce\EventManagement\Calendar\CalendarLogs')) {
            \ZeroSense\Features\WooCommerce\EventManagement\Calendar\CalendarLogs::add($order, 'created', [
                'event_id' => 'pending',
                'trigger_source' => 'automatic',
            ]);
        }
        
        do_action('zs_trigger_class_action_direct', 'zs-calendar-create', $orderId);
        
        $message = sprintf(__('Order #%d: Event created', 'zero-sense'), $orderId);
        $reserved = false;
        
        if ($shouldReserve) {
            $order->update_meta_data(MetaKeys::EVENT_RESERVED, 'yes');
            $order->save_meta_data();
            
            if (class_exists('\ZeroSense\Features\WooCommerce\EventManagement\Calendar\CalendarLogs')) {
                \ZeroSense\Features\WooCommerce\EventManagement\Calendar\CalendarLogs::add($order, 'reserved', [
                    'event_id' => 'pending',
                    'trigger_source' => 'automatic',
                ]);
            }
            
            $message .= ' + ' . __('reserved', 'zero-sense');
            $reserved = true;
        }
        
        // Always trigger sync workflow for FlowMattic (regardless of reservation status)
        do_action('zs_trigger_class_action_direct', 'zs-calendar-sync', $orderId);
        
        wp_send_json_success([
            'message' => $message,
            'action' => 'created',
            'reserved' => $reserved,
        ]);
    }

    private function processDelete($order): void
    {
        $orderId = $order->get_id();
        $orderStatus = $order->get_status();
        $eventId = $order->get_meta(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, true);
        
        if (!$eventId || $eventId === '') {
            wp_send_json_success([
                'message' => sprintf(__('Order #%d (%s): Skipped (no event)', 'zero-sense'), $orderId, $orderStatus),
                'action' => 'skipped',
            ]);
            return;
        }
        
        if (class_exists('\ZeroSense\Features\WooCommerce\EventManagement\Calendar\CalendarLogs')) {
            \ZeroSense\Features\WooCommerce\EventManagement\Calendar\CalendarLogs::add($order, 'deleted', [
                'event_id' => $eventId,
                'trigger_source' => 'automatic',
            ]);
        }
        
        do_action('zs_trigger_class_action_direct', 'zs-calendar-delete', $orderId);
        
        wp_send_json_success([
            'message' => sprintf(__('Order #%d (%s): Event deleted (ID: %s)', 'zero-sense'), $orderId, $orderStatus, $eventId),
            'action' => 'deleted',
        ]);
    }
}
