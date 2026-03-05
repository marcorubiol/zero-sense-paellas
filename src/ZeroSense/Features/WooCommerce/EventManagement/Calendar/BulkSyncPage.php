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
        add_action('admin_post_zs_bulk_sync_calendar', [$this, 'handleBulkSync']);
        add_action('admin_post_zs_bulk_reserve_calendar', [$this, 'handleBulkReserve']);
        add_action('admin_post_zs_bulk_delete_calendar', [$this, 'handleBulkDelete']);
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

    public function renderPage(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bulk Calendar Operations', 'zero-sense'); ?></h1>
            
            <!-- CREATE & RESERVE EVENTS -->
            <div class="card" style="max-width: 800px; margin-bottom: 20px;">
                <h2><?php esc_html_e('Create & Reserve Calendar Events', 'zero-sense'); ?></h2>
                <p><?php esc_html_e('This will create Google Calendar events for all eligible orders and automatically reserve paid orders:', 'zero-sense'); ?></p>
                <ul>
                    <li><?php esc_html_e('Creates events for orders in "Pending", "Deposit Paid", "Fully Paid", "Processing" or "Completed" status', 'zero-sense'); ?></li>
                    <li><?php esc_html_e('Only creates if order does NOT have a Google Calendar event ID yet', 'zero-sense'); ?></li>
                    <li><?php esc_html_e('Requires a valid event date', 'zero-sense'); ?></li>
                    <li><?php esc_html_e('Auto-reserves events for "Deposit Paid", "Fully Paid" and "Completed" orders', 'zero-sense'); ?></li>
                </ul>
                
                <p><strong><?php esc_html_e('How it works:', 'zero-sense'); ?></strong></p>
                <ol style="margin-left: 20px;">
                    <li><?php esc_html_e('Processes 20 orders at a time (5 seconds per order = ~100 seconds per batch)', 'zero-sense'); ?></li>
                    <li><?php esc_html_e('Shows results and progress after each batch', 'zero-sense'); ?></li>
                    <li><?php esc_html_e('Review the results, then click "Continue with next 20 orders" to process more', 'zero-sense'); ?></li>
                    <li><?php esc_html_e('Session tracking prevents duplicates if you refresh or timeout', 'zero-sense'); ?></li>
                </ol>
                
                <p><strong><?php esc_html_e('Warning:', 'zero-sense'); ?></strong> <?php esc_html_e('Do not close this page while a batch is processing.', 'zero-sense'); ?></p>
                
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="zs-bulk-sync-form">
                    <input type="hidden" name="action" value="zs_bulk_sync_calendar">
                    <?php wp_nonce_field('zs_bulk_sync_calendar', 'zs_bulk_sync_nonce'); ?>
                    
                    <p>
                        <button type="submit" class="button button-primary button-large" id="zs-sync-btn">
                            <?php esc_html_e('Create & Reserve All Events', 'zero-sense'); ?>
                        </button>
                    </p>
                </form>
            </div>
            
            <!-- DELETE EVENTS -->
            <div class="card" style="max-width: 800px; margin-bottom: 20px;">
                <h2 style="color: #d63638;"><?php esc_html_e('Delete Calendar Events', 'zero-sense'); ?></h2>
                <p><?php esc_html_e('Select which order statuses you want to delete calendar events for:', 'zero-sense'); ?></p>
                
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="zs-bulk-delete-form">
                    <input type="hidden" name="action" value="zs_bulk_delete_calendar">
                    <?php wp_nonce_field('zs_bulk_delete_calendar', 'zs_bulk_delete_nonce'); ?>
                    
                    <div style="margin: 15px 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px;">
                        <label style="display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="delete_statuses[]" value="pending" checked>
                            <?php esc_html_e('Pending', 'zero-sense'); ?>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="delete_statuses[]" value="deposit-paid" checked>
                            <?php esc_html_e('Deposit Paid', 'zero-sense'); ?>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="delete_statuses[]" value="fully-paid" checked>
                            <?php esc_html_e('Fully Paid', 'zero-sense'); ?>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="delete_statuses[]" value="processing" checked>
                            <?php esc_html_e('Processing', 'zero-sense'); ?>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="delete_statuses[]" value="completed" checked>
                            <?php esc_html_e('Completed', 'zero-sense'); ?>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="delete_statuses[]" value="cancelled" checked>
                            <?php esc_html_e('Cancelled', 'zero-sense'); ?>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="delete_statuses[]" value="failed" checked>
                            <?php esc_html_e('Failed', 'zero-sense'); ?>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px;">
                            <input type="checkbox" name="delete_statuses[]" value="refunded" checked>
                            <?php esc_html_e('Refunded', 'zero-sense'); ?>
                        </label>
                    </div>
                    
                    <p style="margin-top: 10px;">
                        <button type="button" id="zs-select-all-statuses" style="all: unset; cursor: pointer; color: #2271b1; text-decoration: underline; font-size: 13px; margin-right: 10px;">
                            <?php esc_html_e('Select All', 'zero-sense'); ?>
                        </button>
                        <button type="button" id="zs-deselect-all-statuses" style="all: unset; cursor: pointer; color: #2271b1; text-decoration: underline; font-size: 13px;">
                            <?php esc_html_e('Deselect All', 'zero-sense'); ?>
                        </button>
                    </p>
                    
                    <p><strong><?php esc_html_e('How it works:', 'zero-sense'); ?></strong></p>
                    <ol style="margin-left: 20px;">
                        <li><?php esc_html_e('Processes 20 orders at a time (5 seconds per order = ~100 seconds per batch)', 'zero-sense'); ?></li>
                        <li><?php esc_html_e('Shows results and progress after each batch', 'zero-sense'); ?></li>
                        <li><?php esc_html_e('Review the results, then click "Continue with next 20 orders" to process more', 'zero-sense'); ?></li>
                        <li><?php esc_html_e('Session tracking prevents duplicates if you refresh or timeout', 'zero-sense'); ?></li>
                    </ol>
                    
                    <p><strong style="color: #d63638;"><?php esc_html_e('DANGER:', 'zero-sense'); ?></strong> <?php esc_html_e('This will delete calendar events for orders with the selected statuses. This action cannot be undone.', 'zero-sense'); ?></p>
                    
                    <p>
                        <button type="submit" class="button button-large" id="zs-delete-btn" style="background: #d63638; border-color: #d63638; color: #fff;">
                            <?php esc_html_e('Delete Events for Selected Statuses', 'zero-sense'); ?>
                        </button>
                    </p>
                </form>
            </div>
        </div>
        
        <script>
        // Create & Reserve form handler
        document.getElementById('zs-bulk-sync-form').addEventListener('submit', function(e) {
            if (!confirm('<?php esc_js(_e('This will create calendar events for all eligible orders and auto-reserve paid orders. This may take several minutes. Continue?', 'zero-sense')); ?>')) {
                e.preventDefault();
                return;
            }
            
            document.getElementById('zs-sync-btn').disabled = true;
            document.getElementById('zs-sync-btn').textContent = '<?php esc_js(_e('Processing...', 'zero-sense')); ?>';
        });
        
        // Delete form handler
        document.getElementById('zs-bulk-delete-form').addEventListener('submit', function(e) {
            var checkboxes = document.querySelectorAll('input[name="delete_statuses[]"]:checked');
            
            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('<?php esc_js(_e('Please select at least one status to delete events for.', 'zero-sense')); ?>');
                return;
            }
            
            var statuses = Array.from(checkboxes).map(function(cb) { return cb.value; }).join(', ');
            
            if (!confirm('<?php esc_js(_e('⚠️ WARNING: This will DELETE calendar events for orders with these statuses: ', 'zero-sense')); ?>' + statuses + '. <?php esc_js(_e('This cannot be undone. Are you sure?', 'zero-sense')); ?>')) {
                e.preventDefault();
                return;
            }
            
            document.getElementById('zs-delete-btn').disabled = true;
            document.getElementById('zs-delete-btn').textContent = '<?php esc_js(_e('Processing...', 'zero-sense')); ?>';
        });
        
        // Select All / Deselect All handlers
        document.getElementById('zs-select-all-statuses').addEventListener('click', function() {
            document.querySelectorAll('input[name="delete_statuses[]"]').forEach(function(cb) {
                cb.checked = true;
            });
        });
        
        document.getElementById('zs-deselect-all-statuses').addEventListener('click', function() {
            document.querySelectorAll('input[name="delete_statuses[]"]').forEach(function(cb) {
                cb.checked = false;
            });
        });
        </script>
        <?php
    }

    public function handleBulkSync(): void
    {
        check_admin_referer('zs_bulk_sync_calendar', 'zs_bulk_sync_nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'zero-sense'));
        }

        set_time_limit(0);
        
        // Get or initialize session tracking
        if (!session_id()) {
            session_start();
        }
        
        $sessionKey = 'zs_bulk_sync_processed';
        if (!isset($_POST['continue_batch'])) {
            // Fresh start - clear session
            $_SESSION[$sessionKey] = [];
        }
        
        $processedIds = $_SESSION[$sessionKey] ?? [];
        
        // Get all eligible orders
        $args = [
            'limit' => -1,
            'status' => ['pending', 'deposit-paid', 'fully-paid', 'processing', 'completed'],
            'return' => 'ids',
        ];
        
        $allOrderIds = wc_get_orders($args);
        
        // Filter out already processed orders
        $remainingIds = array_diff($allOrderIds, $processedIds);
        
        // Process only next 20
        $batchSize = 20;
        $batchIds = array_slice($remainingIds, 0, $batchSize);
        
        $created = 0;
        $reserved = 0;
        $skipped = 0;
        
        $totalOrders = count($allOrderIds);
        $totalProcessed = count($processedIds);
        $totalRemaining = count($remainingIds);
        
        echo '<div class="wrap"><h1>' . esc_html__('Bulk Create & Reserve Results', 'zero-sense') . '</h1>';
        echo '<div class="card" style="max-width: 800px;">';
        echo '<p><strong>' . sprintf(__('Progress: %d / %d orders processed (%d remaining)', 'zero-sense'), $totalProcessed, $totalOrders, $totalRemaining) . '</strong></p>';
        echo '<p>' . sprintf(__('Processing batch of %d orders...', 'zero-sense'), count($batchIds)) . '</p>';
        echo '<ul>';
        
        foreach ($batchIds as $orderId) {
            // Mark as processed immediately to prevent duplicates
            $_SESSION[$sessionKey][] = $orderId;
            
            $order = wc_get_order($orderId);
            if (!$order) {
                continue;
            }
            
            $eventId = $order->get_meta(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, true);
            
            if ($eventId && $eventId !== '') {
                echo '<li>' . sprintf(__('Order #%d: Skipped (already has event ID)', 'zero-sense'), $orderId) . '</li>';
                $skipped++;
                flush();
                continue;
            }
            
            $eventDate = $order->get_meta(MetaKeys::EVENT_DATE, true);
            if (!$eventDate) {
                echo '<li>' . sprintf(__('Order #%d: Skipped (no event date)', 'zero-sense'), $orderId) . '</li>';
                $skipped++;
                flush();
                continue;
            }
            
            // Determine if order should be auto-reserved
            $orderStatus = $order->get_status();
            $shouldReserve = in_array($orderStatus, ['deposit-paid', 'fully-paid', 'completed'], true);
            
            // Log the create action BEFORE triggering (so it's in DB even if timeout)
            if (class_exists('\ZeroSense\Features\WooCommerce\EventManagement\Calendar\CalendarLogs')) {
                CalendarLogs::add($order, 'created', [
                    'event_id' => 'pending',
                    'trigger_source' => 'automatic',
                ]);
            }
            
            // Trigger FlowMattic create workflow
            do_action('zs_trigger_class_action_direct', 'zs-calendar-create', $orderId);
            
            $message = sprintf(__('Order #%d: Triggered calendar creation', 'zero-sense'), $orderId);
            
            // If should reserve, mark it and trigger sync to update title
            if ($shouldReserve) {
                // Wait for create to finish
                sleep(5);
                
                // Mark as reserved
                $order->update_meta_data(MetaKeys::EVENT_RESERVED, 'yes');
                $order->save_meta_data();
                
                // Log reserve
                if (class_exists('\ZeroSense\Features\WooCommerce\EventManagement\Calendar\CalendarLogs')) {
                    CalendarLogs::add($order, 'reserved', [
                        'event_id' => 'pending',
                        'trigger_source' => 'automatic',
                    ]);
                }
                
                // Trigger sync to update calendar (remove PRE |)
                do_action('zs_trigger_class_action_direct', 'zs-calendar-sync', $orderId);
                
                $message .= ' + ' . __('reserved', 'zero-sense');
                $reserved++;
            }
            
            echo '<li>' . $message . '</li>';
            $created++;
            flush();
            
            // Wait 5 seconds between each order to avoid overwhelming the API
            sleep(5);
        }
        
        echo '</ul>';
        echo '<h3>' . esc_html__('Batch Summary', 'zero-sense') . '</h3>';
        echo '<p>' . sprintf(__('Events created: %d', 'zero-sense'), $created) . '</p>';
        echo '<p>' . sprintf(__('Events auto-reserved: %d', 'zero-sense'), $reserved) . '</p>';
        echo '<p>' . sprintf(__('Orders skipped: %d', 'zero-sense'), $skipped) . '</p>';
        
        // Check if more orders remain
        $newRemaining = count($remainingIds) - count($batchIds);
        
        if ($newRemaining > 0) {
            echo '<hr style="margin: 20px 0;">';
            echo '<p><strong>' . sprintf(__('%d orders remaining. Review the results above, then continue when ready.', 'zero-sense'), $newRemaining) . '</strong></p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display: inline-block; margin-right: 10px;">';
            echo '<input type="hidden" name="action" value="zs_bulk_sync_calendar">';
            echo '<input type="hidden" name="continue_batch" value="1">';
            wp_nonce_field('zs_bulk_sync_calendar', 'zs_bulk_sync_nonce');
            echo '<button type="submit" class="button button-primary button-large">' . sprintf(__('Continue with next %d orders', 'zero-sense'), min($batchSize, $newRemaining)) . '</button>';
            echo '</form>';
        } else {
            echo '<hr style="margin: 20px 0;">';
            echo '<p><strong style="color: #00a32a;">✓ ' . esc_html__('All orders processed!', 'zero-sense') . '</strong></p>';
            // Clear session
            unset($_SESSION[$sessionKey]);
        }
        
        echo '<a href="' . esc_url(admin_url('admin.php?page=zs-calendar-bulk-sync')) . '" class="button">' . esc_html__('Back to Bulk Operations', 'zero-sense') . '</a>';
        echo '</div></div>';
    }
    
    public function handleBulkReserve(): void
    {
        check_admin_referer('zs_bulk_reserve_calendar', 'zs_bulk_reserve_nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'zero-sense'));
        }

        set_time_limit(0);
        
        // Get orders with specific statuses
        $args = [
            'limit' => -1,
            'status' => ['deposit-paid', 'fully-paid', 'completed'],
            'return' => 'ids',
        ];
        
        $orderIds = wc_get_orders($args);
        $reserved = 0;
        $skipped = 0;
        
        echo '<div class="wrap"><h1>' . esc_html__('Bulk Reserve Results', 'zero-sense') . '</h1>';
        echo '<div class="card" style="max-width: 800px;">';
        echo '<p>' . sprintf(__('Scanning %d orders with eligible statuses...', 'zero-sense'), count($orderIds)) . '</p>';
        echo '<ul>';
        
        foreach ($orderIds as $orderId) {
            $order = wc_get_order($orderId);
            if (!$order) {
                continue;
            }
            
            $eventId = $order->get_meta(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, true);
            
            // Skip if no event ID
            if (!$eventId || $eventId === '') {
                echo '<li>' . sprintf(__('Order #%d: Skipped (no event created)', 'zero-sense'), $orderId) . '</li>';
                $skipped++;
                flush();
                continue;
            }
            
            // Skip if already reserved
            $isReserved = $order->get_meta(MetaKeys::EVENT_RESERVED, true) === 'yes';
            if ($isReserved) {
                echo '<li>' . sprintf(__('Order #%d: Skipped (already reserved)', 'zero-sense'), $orderId) . '</li>';
                $skipped++;
                flush();
                continue;
            }
            
            // Mark as reserved BEFORE triggering workflow
            $order->update_meta_data(MetaKeys::EVENT_RESERVED, 'yes');
            $order->save_meta_data();
            
            // Add log entry
            if (class_exists('\\ZeroSense\\Features\\WooCommerce\\EventManagement\\Calendar\\CalendarLogs')) {
                $logData = [
                    'event_id' => $eventId,
                    'trigger_source' => 'automatic',
                ];

                \ZeroSense\Features\WooCommerce\EventManagement\Calendar\CalendarLogs::add(
                    $order,
                    'reserved',
                    $logData
                );
            }
            
            // Trigger FlowMattic workflow to update calendar (remove "PRE |" from title)
            do_action('zs_trigger_class_action_direct', 'zs-calendar-sync', $orderId);
            
            echo '<li>' . sprintf(__('Order #%d: Marked as reserved and triggered sync (Event ID: %s)', 'zero-sense'), $orderId, esc_html($eventId)) . '</li>';
            $reserved++;
            flush();
            
            // Wait 2 seconds between each order to avoid overwhelming the API and timeouts
            sleep(2);
        }
        
        echo '</ul>';
        echo '<h3>' . esc_html__('Summary', 'zero-sense') . '</h3>';
        echo '<p>' . sprintf(__('Total orders scanned: %d', 'zero-sense'), count($orderIds)) . '</p>';
        echo '<p>' . sprintf(__('Events reserved: %d', 'zero-sense'), $reserved) . '</p>';
        echo '<p>' . sprintf(__('Orders skipped: %d', 'zero-sense'), $skipped) . '</p>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=zs-calendar-bulk-sync')) . '" class="button">' . esc_html__('Back', 'zero-sense') . '</a></p>';
        echo '</div></div>';
    }
    
    public function handleBulkDelete(): void
    {
        check_admin_referer('zs_bulk_delete_calendar', 'zs_bulk_delete_nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'zero-sense'));
        }

        // Get selected statuses from form
        $selectedStatuses = isset($_POST['delete_statuses']) && is_array($_POST['delete_statuses']) 
            ? array_map('sanitize_text_field', $_POST['delete_statuses']) 
            : [];
        
        if (empty($selectedStatuses)) {
            wp_die(__('No statuses selected. Please go back and select at least one status.', 'zero-sense'));
        }

        set_time_limit(0);
        
        // Get or initialize session tracking
        if (!session_id()) {
            session_start();
        }
        
        $sessionKey = 'zs_bulk_delete_processed';
        if (!isset($_POST['continue_batch'])) {
            // Fresh start - clear session and store selected statuses
            $_SESSION[$sessionKey] = [];
            $_SESSION[$sessionKey . '_statuses'] = $selectedStatuses;
        } else {
            // Continuing - restore statuses from session
            $selectedStatuses = $_SESSION[$sessionKey . '_statuses'] ?? [];
        }
        
        $processedIds = $_SESSION[$sessionKey] ?? [];
        
        // Get ALL orders
        $args = [
            'limit' => -1,
            'return' => 'ids',
        ];
        
        $allOrderIds = wc_get_orders($args);
        
        // Filter by status and exclude already processed
        $eligibleIds = [];
        foreach ($allOrderIds as $orderId) {
            if (in_array($orderId, $processedIds, true)) {
                continue;
            }
            $order = wc_get_order($orderId);
            if ($order && in_array($order->get_status(), $selectedStatuses, true)) {
                $eligibleIds[] = $orderId;
            }
        }
        
        // Process only next 20
        $batchSize = 20;
        $batchIds = array_slice($eligibleIds, 0, $batchSize);
        
        $deleted = 0;
        $skipped = 0;
        
        $totalEligible = count($eligibleIds);
        $totalProcessed = count(array_intersect($processedIds, $allOrderIds));
        $totalRemaining = count($eligibleIds);
        
        echo '<div class="wrap"><h1>' . esc_html__('Bulk Delete Results', 'zero-sense') . '</h1>';
        echo '<div class="card" style="max-width: 800px;">';
        echo '<p><strong>' . sprintf(__('Statuses: %s', 'zero-sense'), implode(', ', $selectedStatuses)) . '</strong></p>';
        echo '<p><strong>' . sprintf(__('Progress: %d eligible orders found, %d remaining', 'zero-sense'), $totalEligible + $totalProcessed, $totalRemaining) . '</strong></p>';
        echo '<p>' . sprintf(__('Processing batch of %d orders...', 'zero-sense'), count($batchIds)) . '</p>';
        echo '<ul>';
        
        foreach ($batchIds as $orderId) {
            // Mark as processed immediately
            $_SESSION[$sessionKey][] = $orderId;
            
            $order = wc_get_order($orderId);
            if (!$order) {
                continue;
            }
            
            $orderStatus = $order->get_status();
            
            $eventId = $order->get_meta(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, true);
            
            // Skip if no event ID
            if (!$eventId || $eventId === '') {
                echo '<li>' . sprintf(__('Order #%d (%s): Skipped (no event)', 'zero-sense'), $orderId, $orderStatus) . '</li>';
                $skipped++;
                flush();
                continue;
            }
            
            // Log BEFORE triggering (so it's in DB even if timeout)
            if (class_exists('\ZeroSense\Features\WooCommerce\EventManagement\Calendar\CalendarLogs')) {
                CalendarLogs::add($order, 'deleted', [
                    'event_id' => $eventId,
                    'trigger_source' => 'automatic',
                ]);
            }
            
            // Trigger FlowMattic workflow for deletion
            do_action('zs_trigger_class_action_direct', 'zs-calendar-delete', $orderId);
            
            echo '<li>' . sprintf(__('Order #%d (%s): Triggered calendar deletion (Event ID: %s)', 'zero-sense'), $orderId, $orderStatus, esc_html($eventId)) . '</li>';
            $deleted++;
            flush();
            
            // Wait 5 seconds between each order to avoid overwhelming the API and timeouts
            sleep(5);
        }
        
        echo '</ul>';
        echo '<h3>' . esc_html__('Batch Summary', 'zero-sense') . '</h3>';
        echo '<p>' . sprintf(__('Events deleted: %d', 'zero-sense'), $deleted) . '</p>';
        echo '<p>' . sprintf(__('Orders skipped (no event): %d', 'zero-sense'), $skipped) . '</p>';
        
        // Check if more orders remain
        $newRemaining = count($eligibleIds) - count($batchIds);
        
        if ($newRemaining > 0) {
            echo '<hr style="margin: 20px 0;">';
            echo '<p><strong>' . sprintf(__('%d orders remaining. Review the results above, then continue when ready.', 'zero-sense'), $newRemaining) . '</strong></p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display: inline-block; margin-right: 10px;">';
            echo '<input type="hidden" name="action" value="zs_bulk_delete_calendar">';
            echo '<input type="hidden" name="continue_batch" value="1">';
            wp_nonce_field('zs_bulk_delete_calendar', 'zs_bulk_delete_nonce');
            echo '<button type="submit" class="button button-primary button-large">' . sprintf(__('Continue with next %d orders', 'zero-sense'), min($batchSize, $newRemaining)) . '</button>';
            echo '</form>';
        } else {
            echo '<hr style="margin: 20px 0;">';
            echo '<p><strong style="color: #00a32a;">✓ ' . esc_html__('All eligible orders processed!', 'zero-sense') . '</strong></p>';
            // Clear session
            unset($_SESSION[$sessionKey]);
            unset($_SESSION[$sessionKey . '_statuses']);
        }
        
        echo '<a href="' . esc_url(admin_url('admin.php?page=zs-calendar-bulk-sync')) . '" class="button">' . esc_html__('Back to Bulk Operations', 'zero-sense') . '</a>';
        echo '</div></div>';
    }
}
