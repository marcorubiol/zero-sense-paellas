<?php
namespace ZeroSense\Features\WooCommerce\EventManagement\Calendar;

use ZeroSense\Features\WooCommerce\EventManagement\Support\MetaKeys;

class BulkSyncPage
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addAdminPage']);
        add_action('admin_post_zs_bulk_sync_calendar', [$this, 'handleBulkSync']);
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
            <h1><?php esc_html_e('Bulk Calendar Sync', 'zero-sense'); ?></h1>
            
            <div class="card" style="max-width: 800px;">
                <h2><?php esc_html_e('Sync All Orders to Google Calendar', 'zero-sense'); ?></h2>
                <p><?php esc_html_e('This will trigger the FlowMattic workflow to create Google Calendar events for all orders that:', 'zero-sense'); ?></p>
                <ul>
                    <li><?php esc_html_e('Are in "Processing" or "Completed" status', 'zero-sense'); ?></li>
                    <li><?php esc_html_e('Do NOT have a Google Calendar event ID yet', 'zero-sense'); ?></li>
                    <li><?php esc_html_e('Have a valid event date', 'zero-sense'); ?></li>
                </ul>
                
                <p><strong><?php esc_html_e('Warning:', 'zero-sense'); ?></strong> <?php esc_html_e('This process may take several minutes depending on the number of orders. Do not close this page until it completes.', 'zero-sense'); ?></p>
                
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="zs-bulk-sync-form">
                    <input type="hidden" name="action" value="zs_bulk_sync_calendar">
                    <?php wp_nonce_field('zs_bulk_sync_calendar', 'zs_bulk_sync_nonce'); ?>
                    
                    <p>
                        <button type="submit" class="button button-primary button-large" id="zs-sync-btn">
                            <?php esc_html_e('Start Bulk Sync', 'zero-sense'); ?>
                        </button>
                    </p>
                </form>
                
                <div id="zs-sync-progress" style="display:none; margin-top: 20px;">
                    <h3><?php esc_html_e('Syncing...', 'zero-sense'); ?></h3>
                    <div style="background: #f0f0f0; padding: 10px; border-radius: 4px; font-family: monospace; max-height: 400px; overflow-y: auto;" id="zs-sync-log"></div>
                </div>
            </div>
        </div>
        
        <script>
        document.getElementById('zs-bulk-sync-form').addEventListener('submit', function(e) {
            if (!confirm('<?php esc_js(_e('Are you sure you want to sync all orders? This may take several minutes.', 'zero-sense')); ?>')) {
                e.preventDefault();
                return;
            }
            
            document.getElementById('zs-sync-btn').disabled = true;
            document.getElementById('zs-sync-btn').textContent = '<?php esc_js(_e('Syncing...', 'zero-sense')); ?>';
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
            'status' => ['processing', 'completed'],
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
            
            $result = do_action('zs_calendar_create_event_trigger', $orderId, 'automatic');
            
            echo '<li>' . sprintf(__('Order #%d: Triggered calendar creation', 'zero-sense'), $orderId) . '</li>';
            $synced++;
            flush();
            
            usleep(500000);
        }
        
        echo '</ul>';
        echo '<h3>' . esc_html__('Summary', 'zero-sense') . '</h3>';
        echo '<p>' . sprintf(__('Synced: %d', 'zero-sense'), $synced) . '</p>';
        echo '<p>' . sprintf(__('Skipped: %d', 'zero-sense'), $skipped) . '</p>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=zs-calendar-bulk-sync')) . '" class="button">' . esc_html__('Back', 'zero-sense') . '</a></p>';
        echo '</div></div>';
    }
}
