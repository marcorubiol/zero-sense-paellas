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
        return 'WooCommerce';
    }

    public function isToggleable(): bool
    {
        return true;
    }

    public function isEnabled(): bool
    {
        return (bool) get_option('zero_sense_feature_calendar_bulk_operations', 0);
    }

    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        add_action('admin_menu', [$this, 'addAdminPage']);
        add_action('admin_post_zs_bulk_sync_calendar', [$this, 'handleBulkSync']);
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
            
            <!-- DELETE EVENTS -->
            <div class="card" style="max-width: 800px; margin-bottom: 20px;">
                <h2 style="color: #d63638;"><?php esc_html_e('Delete All Calendar Events', 'zero-sense'); ?></h2>
                <p><?php esc_html_e('This will trigger the FlowMattic workflow to delete Google Calendar events for all orders that:', 'zero-sense'); ?></p>
                <ul>
                    <li><?php esc_html_e('Have a Google Calendar event ID', 'zero-sense'); ?></li>
                </ul>
                
                <p><strong style="color: #d63638;"><?php esc_html_e('DANGER:', 'zero-sense'); ?></strong> <?php esc_html_e('This will delete ALL calendar events and their metadata. This action cannot be undone. Use with extreme caution.', 'zero-sense'); ?></p>
                
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="zs-bulk-delete-form">
                    <input type="hidden" name="action" value="zs_bulk_delete_calendar">
                    <?php wp_nonce_field('zs_bulk_delete_calendar', 'zs_bulk_delete_nonce'); ?>
                    
                    <p>
                        <button type="submit" class="button button-large" id="zs-delete-btn" style="background: #d63638; border-color: #d63638; color: #fff;">
                            <?php esc_html_e('Delete All Events', 'zero-sense'); ?>
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
        document.getElementById('zs-bulk-sync-form').addEventListener('submit', function(e) {
            if (!confirm('<?php esc_js(_e('Are you sure you want to create calendar events for all orders? This may take several minutes.', 'zero-sense')); ?>')) {
                e.preventDefault();
                return;
            }
            
            document.getElementById('zs-sync-btn').disabled = true;
            document.getElementById('zs-sync-btn').textContent = '<?php esc_js(_e('Creating...', 'zero-sense')); ?>';
            document.getElementById('zs-sync-progress').style.display = 'block';
        });
        
        document.getElementById('zs-bulk-delete-form').addEventListener('submit', function(e) {
            if (!confirm('<?php esc_js(_e('⚠️ WARNING: This will DELETE ALL calendar events. This cannot be undone. Are you absolutely sure?', 'zero-sense')); ?>')) {
                e.preventDefault();
                return;
            }
            
            if (!confirm('<?php esc_js(_e('This is your last chance. Type YES in the next prompt to confirm deletion.', 'zero-sense')); ?>')) {
                e.preventDefault();
                return;
            }
            
            var confirmation = prompt('<?php esc_js(_e('Type YES in capital letters to confirm deletion:', 'zero-sense')); ?>');
            if (confirmation !== 'YES') {
                e.preventDefault();
                alert('<?php esc_js(_e('Deletion cancelled.', 'zero-sense')); ?>');
                return;
            }
            
            document.getElementById('zs-delete-btn').disabled = true;
            document.getElementById('zs-delete-btn').textContent = '<?php esc_js(_e('Deleting...', 'zero-sense')); ?>';
            document.getElementById('zs-sync-progress').style.display = 'block';
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
    
    public function handleBulkDelete(): void
    {
        check_admin_referer('zs_bulk_delete_calendar', 'zs_bulk_delete_nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'zero-sense'));
        }

        set_time_limit(0);
        
        $args = [
            'limit' => -1,
            'return' => 'ids',
            'meta_query' => [
                [
                    'key' => MetaKeys::GOOGLE_CALENDAR_EVENT_ID,
                    'compare' => 'EXISTS',
                ],
            ],
        ];
        
        $orderIds = wc_get_orders($args);
        $deleted = 0;
        $skipped = 0;
        
        echo '<div class="wrap"><h1>' . esc_html__('Bulk Delete Results', 'zero-sense') . '</h1>';
        echo '<div class="card" style="max-width: 800px;"><ul>';
        
        foreach ($orderIds as $orderId) {
            $order = wc_get_order($orderId);
            if (!$order) {
                continue;
            }
            
            $eventId = $order->get_meta(MetaKeys::GOOGLE_CALENDAR_EVENT_ID, true);
            
            if (!$eventId || $eventId === '') {
                echo '<li>' . sprintf(__('Order #%d: Skipped (no event ID)', 'zero-sense'), $orderId) . '</li>';
                $skipped++;
                flush();
                continue;
            }
            
            // Trigger FlowMattic workflow for deletion
            do_action('zs_trigger_class_action_direct', 'zs-calendar-delete', $orderId);
            
            echo '<li>' . sprintf(__('Order #%d: Triggered calendar deletion', 'zero-sense'), $orderId) . '</li>';
            $deleted++;
            flush();
            
            // Wait 2 seconds between each order to avoid overwhelming the API and timeouts
            sleep(2);
        }
        
        echo '</ul>';
        echo '<h3>' . esc_html__('Summary', 'zero-sense') . '</h3>';
        echo '<p>' . sprintf(__('Deleted: %d', 'zero-sense'), $deleted) . '</p>';
        echo '<p>' . sprintf(__('Skipped: %d', 'zero-sense'), $skipped) . '</p>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=zs-calendar-bulk-sync')) . '" class="button">' . esc_html__('Back', 'zero-sense') . '</a></p>';
        echo '</div></div>';
    }
}
