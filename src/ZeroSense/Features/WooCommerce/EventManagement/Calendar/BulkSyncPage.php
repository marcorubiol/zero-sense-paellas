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
            
            <!-- CREATE EVENTS -->
            <div class="card" style="max-width: 800px; margin-bottom: 20px;">
                <h2><?php esc_html_e('Create Calendar Events', 'zero-sense'); ?></h2>
                <p><?php esc_html_e('This will trigger the FlowMattic workflow to create Google Calendar events for all orders that:', 'zero-sense'); ?></p>
                <ul>
                    <li><?php esc_html_e('Are in "Pending", "Deposit Paid", "Fully Paid", "Processing" or "Completed" status', 'zero-sense'); ?></li>
                    <li><?php esc_html_e('Do NOT have a Google Calendar event ID yet', 'zero-sense'); ?></li>
                    <li><?php esc_html_e('Have a valid event date', 'zero-sense'); ?></li>
                </ul>
                
                <p><strong><?php esc_html_e('Warning:', 'zero-sense'); ?></strong> <?php esc_html_e('This process may take several minutes (2 seconds per order). Do not close this page until it completes.', 'zero-sense'); ?></p>
                
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="zs-bulk-sync-form">
                    <input type="hidden" name="action" value="zs_bulk_sync_calendar">
                    <?php wp_nonce_field('zs_bulk_sync_calendar', 'zs_bulk_sync_nonce'); ?>
                    
                    <p>
                        <button type="submit" class="button button-primary button-large" id="zs-sync-btn">
                            <?php esc_html_e('Create All Events', 'zero-sense'); ?>
                        </button>
                    </p>
                </form>
            </div>
            
            <!-- RESERVE EVENTS -->
            <div class="card" style="max-width: 800px; margin-bottom: 20px; border-left: 4px solid #f0b849;">
                <h2 style="color: #996800;"><?php esc_html_e('Reserve Calendar Events', 'zero-sense'); ?></h2>
                <p><?php esc_html_e('This will trigger the FlowMattic workflow to mark events as "reserved" for all orders that:', 'zero-sense'); ?></p>
                <ul>
                    <li><?php esc_html_e('Are in "Deposit Paid", "Fully Paid" or "Completed" status', 'zero-sense'); ?></li>
                    <li><?php esc_html_e('Have a Google Calendar event ID (event already created)', 'zero-sense'); ?></li>
                    <li><?php esc_html_e('Are NOT already marked as reserved', 'zero-sense'); ?></li>
                </ul>
                
                <p><strong><?php esc_html_e('Note:', 'zero-sense'); ?></strong> <?php esc_html_e('This will update the event in Google Calendar (title, color, etc.) and mark it as reserved. Process takes 2 seconds per order.', 'zero-sense'); ?></p>
                
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="zs-bulk-reserve-form">
                    <input type="hidden" name="action" value="zs_bulk_reserve_calendar">
                    <?php wp_nonce_field('zs_bulk_reserve_calendar', 'zs_bulk_reserve_nonce'); ?>
                    
                    <p>
                        <button type="submit" class="button button-secondary button-large" id="zs-reserve-btn">
                            <?php esc_html_e('Reserve All Eligible Events', 'zero-sense'); ?>
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
                    
                    <p><strong style="color: #d63638;"><?php esc_html_e('DANGER:', 'zero-sense'); ?></strong> <?php esc_html_e('This will delete calendar events for orders with the selected statuses. This action cannot be undone.', 'zero-sense'); ?></p>
                    
                    <p>
                        <button type="submit" id="zs-delete-btn" style="background: none; border: none; color: #d63638; text-decoration: underline; cursor: pointer; padding: 0; font-size: 13px;">
                            <?php esc_html_e('Delete Events for Selected Statuses', 'zero-sense'); ?>
                        </button>
                    </p>
                </form>
            </div>
                
                <div id="zs-sync-progress" style="display:none; margin-top: 20px;">
                    <h3><?php esc_html_e('Syncing...', 'zero-sense'); ?></h3>
                    <div style="background: #f0f0f0; padding: 10px; border-radius: 4px; font-family: monospace; max-height: 400px; overflow-y: auto;" id="zs-sync-log"></div>
                </div>
            </div>
        </div>
        
        <script>
        // Create form handler
        document.getElementById('zs-bulk-sync-form').addEventListener('submit', function(e) {
            if (!confirm('<?php esc_js(_e('Are you sure you want to create calendar events for all eligible orders? This may take several minutes.', 'zero-sense')); ?>')) {
                e.preventDefault();
                return;
            }
            
            document.getElementById('zs-sync-btn').disabled = true;
            document.getElementById('zs-sync-btn').textContent = '<?php esc_js(_e('Creating...', 'zero-sense')); ?>';
            document.getElementById('zs-sync-progress').style.display = 'block';
        });
        
        // Reserve form handler
        document.getElementById('zs-bulk-reserve-form').addEventListener('submit', function(e) {
            if (!confirm('<?php esc_js(_e('This will reserve all events for Deposit Paid, Fully Paid, and Completed orders. Continue?', 'zero-sense')); ?>')) {
                e.preventDefault();
                return;
            }
            
            document.getElementById('zs-reserve-btn').disabled = true;
            document.getElementById('zs-reserve-btn').textContent = '<?php esc_js(_e('Reserving...', 'zero-sense')); ?>';
            document.getElementById('zs-sync-progress').style.display = 'block';
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
            document.getElementById('zs-delete-btn').textContent = '<?php esc_js(_e('Deleting...', 'zero-sense')); ?>';
            document.getElementById('zs-sync-progress').style.display = 'block';
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
        
        $args = [
            'limit' => -1,
            'status' => ['pending', 'deposit-paid', 'fully-paid', 'processing', 'completed'],
            'return' => 'ids',
        ];
        
        $orderIds = wc_get_orders($args);
        $synced = 0;
        $skipped = 0;
        $errors = 0;
        
        echo '<div class="wrap"><h1>' . esc_html__('Bulk Sync Results', 'zero-sense') . '</h1>';
        echo '<div class="card" style="max-width: 800px;"><ul>';
        
        foreach ($orderIds as $orderId) {
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
            
            // Trigger FlowMattic workflow
            do_action('zs_trigger_class_action_direct', 'zs-calendar-create', $orderId);
            
            echo '<li>' . sprintf(__('Order #%d: Triggered calendar creation', 'zero-sense'), $orderId) . '</li>';
            $synced++;
            flush();
            
            // Wait 2 seconds between each order to avoid overwhelming the API and timeouts
            sleep(2);
        }
        
        echo '</ul>';
        echo '<h3>' . esc_html__('Summary', 'zero-sense') . '</h3>';
        echo '<p>' . sprintf(__('Synced: %d', 'zero-sense'), $synced) . '</p>';
        echo '<p>' . sprintf(__('Skipped: %d', 'zero-sense'), $skipped) . '</p>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=zs-calendar-bulk-sync')) . '" class="button">' . esc_html__('Back', 'zero-sense') . '</a></p>';
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
            
            // Trigger FlowMattic workflow for reservation
            do_action('zs_trigger_class_action_direct', 'zs-calendar-update', $orderId);
            
            echo '<li>' . sprintf(__('Order #%d: Triggered calendar reservation (Event ID: %s)', 'zero-sense'), $orderId, esc_html($eventId)) . '</li>';
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
        
        // Get ALL orders without any filtering
        $args = [
            'limit' => -1,
            'return' => 'ids',
        ];
        
        $orderIds = wc_get_orders($args);
        $deleted = 0;
        $skipped = 0;
        $statusMismatch = 0;
        
        echo '<div class="wrap"><h1>' . esc_html__('Bulk Delete Results', 'zero-sense') . '</h1>';
        echo '<div class="card" style="max-width: 800px;">';
        echo '<p>' . sprintf(__('Scanning %d orders for statuses: %s', 'zero-sense'), count($orderIds), implode(', ', $selectedStatuses)) . '</p>';
        echo '<ul>';
        
        foreach ($orderIds as $orderId) {
            $order = wc_get_order($orderId);
            if (!$order) {
                continue;
            }
            
            // Check if order status matches selected statuses
            $orderStatus = $order->get_status();
            if (!in_array($orderStatus, $selectedStatuses, true)) {
                $statusMismatch++;
                continue;
            }
            
            $eventId = $order->get_meta(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, true);
            
            // Skip if no event ID
            if (!$eventId || $eventId === '') {
                echo '<li>' . sprintf(__('Order #%d (%s): Skipped (no event)', 'zero-sense'), $orderId, $orderStatus) . '</li>';
                $skipped++;
                flush();
                continue;
            }
            
            // Trigger FlowMattic workflow for deletion
            do_action('zs_trigger_class_action_direct', 'zs-calendar-delete', $orderId);
            
            echo '<li>' . sprintf(__('Order #%d (%s): Triggered calendar deletion (Event ID: %s)', 'zero-sense'), $orderId, $orderStatus, esc_html($eventId)) . '</li>';
            $deleted++;
            flush();
            
            // Wait 2 seconds between each order to avoid overwhelming the API and timeouts
            sleep(2);
        }
        
        echo '</ul>';
        echo '<h3>' . esc_html__('Summary', 'zero-sense') . '</h3>';
        echo '<p>' . sprintf(__('Total orders scanned: %d', 'zero-sense'), count($orderIds)) . '</p>';
        echo '<p>' . sprintf(__('Events deleted: %d', 'zero-sense'), $deleted) . '</p>';
        echo '<p>' . sprintf(__('Orders skipped (no event): %d', 'zero-sense'), $skipped) . '</p>';
        echo '<p>' . sprintf(__('Orders skipped (status mismatch): %d', 'zero-sense'), $statusMismatch) . '</p>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=zs-calendar-bulk-sync')) . '" class="button">' . esc_html__('Back', 'zero-sense') . '</a></p>';
        echo '</div></div>';
    }
}
