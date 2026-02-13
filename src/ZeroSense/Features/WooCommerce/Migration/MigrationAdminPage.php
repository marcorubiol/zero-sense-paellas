<?php
namespace ZeroSense\Features\WooCommerce\Migration;

use ZeroSense\Core\FeatureInterface;

class MigrationAdminPage implements FeatureInterface
{
    private MetaBoxMigrator $migrator;

    private static bool $hooksRegistered = false;

    public function __construct()
    {
        $this->migrator = new MetaBoxMigrator();
    }

    public function getName(): string
    {
        return __('MetaBox Migration', 'zero-sense');
    }

    public function getDescription(): string
    {
        return __('Migrate custom fields from MetaBox to ZeroSense plugin for HPOS compatibility.', 'zero-sense');
    }

    public function getCategory(): string
    {
        return 'WooCommerce';
    }

    public function isEnabled(): bool
    {
        return (bool) get_option($this->getOptionName(), true);
    }

    public function getOptionName(): string
    {
        return 'zs_metabox_migration_enabled';
    }

    public function isToggleable(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 5;
    }

    public function getConditions(): array
    {
        return ['class_exists:WooCommerce'];
    }

    public function init(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (self::$hooksRegistered) {
            return;
        }

        self::$hooksRegistered = true;

        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_menu', [$this, 'dedupeAdminMenu'], 999);
        add_action('admin_post_zs_metabox_migrate', [$this, 'handleMigration']);
        add_action('admin_post_zs_metabox_reset', [$this, 'handleReset']);
        add_action('admin_post_zs_metabox_rollback', [$this, 'handleRollback']);
        add_action('admin_post_zs_metabox_preview', [$this, 'handlePreview']);
        add_action('wp_ajax_zs_metabox_status', [$this, 'ajaxGetStatus']);
    }

    public function addAdminMenu(): void
    {
        global $submenu;

        if (isset($submenu['woocommerce'])) {
            foreach ($submenu['woocommerce'] as $menu_item) {
                if (isset($menu_item[2]) && $menu_item[2] === 'zs_metabox_migration') {
                    return;
                }
            }
        }

        add_submenu_page(
            'woocommerce',
            __('MetaBox Migration', 'zero-sense'),
            __('MetaBox Migration', 'zero-sense'),
            'manage_options',
            'zs_metabox_migration',
            [$this, 'renderAdminPage']
        );
    }

    public function dedupeAdminMenu(): void
    {
        global $submenu;

        if (!isset($submenu['woocommerce'])) {
            return;
        }

        $unique = [];
        $filtered = [];

        foreach ($submenu['woocommerce'] as $menu_item) {
            $slug = $menu_item[2] ?? '';
            if ($slug === 'zs_metabox_migration') {
                if (isset($unique[$slug])) {
                    continue;
                }
                $unique[$slug] = true;
            }

            $filtered[] = $menu_item;
        }

        $submenu['woocommerce'] = $filtered;
    }

    public function renderAdminPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'zero-sense'));
        }

        $load_data = isset($_GET['zs_migration_load']) && sanitize_text_field(wp_unslash($_GET['zs_migration_load'])) === '1';
        $status = $load_data ? $this->migrator->getMigrationStatus() : null;
        $sample_orders = $load_data ? $this->migrator->getSampleOrders(3) : [];
        $migrated_orders = $load_data ? $this->migrator->getMigratedOrders(20) : [];

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(__('MetaBox to ZeroSense Migration', 'zero-sense')); ?></h1>

            <div class="notice notice-info">
                <p>
                    <strong><?php esc_html_e('Important:', 'zero-sense'); ?></strong>
                    <?php esc_html_e('This tool migrates custom fields from MetaBox to ZeroSense plugin fields for HPOS compatibility. Always backup your database before proceeding.', 'zero-sense'); ?>
                </p>
            </div>

            <div class="zs-migration-dashboard">
                <?php if (!$load_data): ?>
                    <div class="notice notice-warning">
                        <p>
                            <?php esc_html_e('Migration data is loaded on demand to avoid timeouts. Click to load current status.', 'zero-sense'); ?>
                        </p>
                    </div>
                    <p>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=zs_metabox_migration&zs_migration_load=1')); ?>">
                            <?php esc_html_e('Load migration status', 'zero-sense'); ?>
                        </a>
                    </p>
                <?php endif; ?>

                <div class="zs-migration-status-card">
                    <h2><?php esc_html_e('Migration Status', 'zero-sense'); ?></h2>
                    <div class="zs-status-grid">
                        <div class="zs-status-item">
                            <span class="zs-status-label"><?php esc_html_e('Total Orders:', 'zero-sense'); ?></span>
                            <span class="zs-status-value"><?php echo $status ? esc_html($status['total_orders']) : '-'; ?></span>
                        </div>
                        <div class="zs-status-item">
                            <span class="zs-status-label"><?php esc_html_e('Migrated:', 'zero-sense'); ?></span>
                            <span class="zs-status-value zs-success"><?php echo $status ? esc_html($status['migrated_orders']) : '-'; ?></span>
                        </div>
                        <div class="zs-status-item">
                            <span class="zs-status-label"><?php esc_html_e('Pending:', 'zero-sense'); ?></span>
                            <span class="zs-status-value <?php echo $status && $status['pending_orders'] > 0 ? 'zs-warning' : 'zs-success'; ?>">
                                <?php echo $status ? esc_html($status['pending_orders']) : '-'; ?>
                            </span>
                        </div>
                    </div>

                    <?php if ($status && $status['migration_complete']): ?>
                        <div class="notice notice-success">
                            <p><?php esc_html_e('✅ Migration complete! All orders have been migrated.', 'zero-sense'); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ($status && $status['last_migration']): ?>
                        <p class="zs-last-migration">
                            <strong><?php esc_html_e('Last migration:', 'zero-sense'); ?></strong>
                            <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($status['last_migration']))); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <?php if (!empty($sample_orders)): ?>
                <div class="zs-migration-preview-card">
                    <h2><?php esc_html_e('Sample Orders (Preview)', 'zero-sense'); ?></h2>
                    <div class="zs-preview-table">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Order', 'zero-sense'); ?></th>
                                    <th><?php esc_html_e('Status', 'zero-sense'); ?></th>
                                    <th><?php esc_html_e('MetaBox Fields', 'zero-sense'); ?></th>
                                    <th><?php esc_html_e('ZeroSense Fields', 'zero-sense'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sample_orders as $order): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url(get_edit_post_link($order['order_id'])); ?>">
                                            #<?php echo esc_html($order['order_number']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <mark class="order-status status-<?php echo esc_attr($order['status']); ?>">
                                            <?php echo esc_html(ucfirst($order['status'])); ?>
                                        </mark>
                                    </td>
                                    <td>
                                        <?php if (!empty($order['metabox_fields'])): ?>
                                            <ul class="zs-field-list">
                                                <?php foreach ($order['metabox_fields'] as $key => $value): ?>
                                                    <li><strong><?php echo esc_html($key); ?>:</strong> <?php echo esc_html(is_array($value) ? print_r($value, true) : $value); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <em><?php esc_html_e('None', 'zero-sense'); ?></em>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($order['zerosense_fields'])): ?>
                                            <ul class="zs-field-list">
                                                <?php foreach ($order['zerosense_fields'] as $key => $value): ?>
                                                    <li><strong><?php echo esc_html($key); ?>:</strong> <?php echo esc_html(is_array($value) ? print_r($value, true) : $value); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <em><?php esc_html_e('None', 'zero-sense'); ?></em>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                $last_results = get_transient('zs_metabox_migration_results');
                if ($last_results && !empty($last_results['details'])):
                ?>
                <div class="zs-migration-results-card">
                    <h2><?php esc_html_e('Last Migration Results', 'zero-sense'); ?></h2>
                    <p>
                        <strong><?php esc_html_e('Success:', 'zero-sense'); ?></strong> <?php echo esc_html($last_results['success']); ?> &mdash;
                        <strong><?php esc_html_e('Errors:', 'zero-sense'); ?></strong> <?php echo esc_html($last_results['errors']); ?> &mdash;
                        <strong><?php esc_html_e('Skipped:', 'zero-sense'); ?></strong> <?php echo esc_html($last_results['skipped']); ?>
                    </p>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:80px"><?php esc_html_e('Order', 'zero-sense'); ?></th>
                                <th style="width:70px"><?php esc_html_e('Status', 'zero-sense'); ?></th>
                                <th><?php esc_html_e('Migrated Fields', 'zero-sense'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($last_results['details'] as $detail): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($detail['order_id'])): ?>
                                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $detail['order_id'] . '&action=edit')); ?>">#<?php echo esc_html($detail['order_id']); ?></a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($detail['success'])): ?>
                                        <span style="color:green">&#10003;</span>
                                    <?php else: ?>
                                        <span style="color:orange"><?php echo esc_html($detail['status'] ?? 'skip'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($detail['migrated_fields'])): ?>
                                        <ul style="margin:0;padding-left:16px">
                                        <?php foreach ($detail['migrated_fields'] as $f): ?>
                                            <li>
                                                <strong><?php echo esc_html($f['field']); ?></strong>
                                                <code><?php echo esc_html(is_scalar($f['old_value']) ? $f['old_value'] : json_encode($f['old_value'])); ?></code>
                                                &rarr;
                                                <code><?php echo esc_html(is_scalar($f['value']) ? $f['value'] : json_encode($f['value'])); ?></code>
                                            </li>
                                        <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <em><?php echo esc_html($detail['message'] ?? __('No fields changed', 'zero-sense')); ?></em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if (!empty($migrated_orders)): ?>
                <div class="zs-migration-migrated-card">
                    <h2><?php echo esc_html(sprintf(__('Migrated Orders (%d most recent)', 'zero-sense'), count($migrated_orders))); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:80px"><?php esc_html_e('Order', 'zero-sense'); ?></th>
                                <th style="width:90px"><?php esc_html_e('Status', 'zero-sense'); ?></th>
                                <th><?php esc_html_e('ZeroSense Fields', 'zero-sense'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($migrated_orders as $mo): ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-orders&action=edit&id=' . $mo['order_id'])); ?>">#<?php echo esc_html($mo['order_number']); ?></a>
                                </td>
                                <td>
                                    <mark class="order-status status-<?php echo esc_attr($mo['status']); ?>">
                                        <?php echo esc_html(wc_get_order_status_name($mo['status'])); ?>
                                    </mark>
                                </td>
                                <td>
                                    <?php if (!empty($mo['fields'])): ?>
                                        <ul style="margin:0;padding-left:16px">
                                        <?php foreach ($mo['fields'] as $key => $val): ?>
                                            <li><strong><?php echo esc_html($key); ?>:</strong> <?php echo esc_html($val); ?></li>
                                        <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <em><?php esc_html_e('No fields', 'zero-sense'); ?></em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <div class="zs-migration-actions">
                    <h2><?php esc_html_e('Actions', 'zero-sense'); ?></h2>

                    <?php settings_errors('zs_metabox_migration'); ?>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('zs_metabox_migrate'); ?>
                        <input type="hidden" name="action" value="zs_metabox_migrate">
                        <p>
                            <button type="submit" class="button button-primary" onclick="return confirm('<?php esc_attr_e('This will migrate all pending orders. Continue?', 'zero-sense'); ?>');">
                                <?php esc_html_e('Migrate All Pending Orders', 'zero-sense'); ?>
                            </button>
                        </p>
                    </form>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('zs_metabox_preview'); ?>
                        <input type="hidden" name="action" value="zs_metabox_preview">
                        <p>
                            <button type="submit" class="button">
                                <?php esc_html_e('Refresh Preview', 'zero-sense'); ?>
                            </button>
                        </p>
                    </form>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('zs_metabox_reset'); ?>
                        <input type="hidden" name="action" value="zs_metabox_reset">
                        <p>
                            <button type="submit" class="button button-secondary" onclick="return confirm('<?php esc_attr_e('This will reset migration status for all orders. Use this after changing field mappings. Continue?', 'zero-sense'); ?>');">
                                <?php esc_html_e('Reset Migration Status', 'zero-sense'); ?>
                            </button>
                        </p>
                    </form>

                </div>
            </div>
        </div>
        <?php
    }

    public function handleMigration(): void
    {
        error_log('[ZS Migration] handleMigration() called');
        
        if (!current_user_can('manage_options')) {
            error_log('[ZS Migration] User lacks manage_options permission');
            wp_die(__('You do not have permission to perform this action.', 'zero-sense'));
        }

        error_log('[ZS Migration] Checking nonce...');
        check_admin_referer('zs_metabox_migrate');
        error_log('[ZS Migration] Nonce verified, starting migration...');

        $results = $this->migrator->migrateAll();
        $message = sprintf(
            __('Migration complete. %1$d orders migrated, %2$d errors, %3$d skipped.', 'zero-sense'),
            $results['success'],
            $results['errors'],
            $results['skipped']
        );

        error_log('[ZS Migration] Migration results: ' . json_encode($results));
        
        if ($results['errors'] > 0) {
            error_log('[ZS Migration] Migration completed with errors');
            add_settings_error('zs_metabox_migration', 'migration_error', $message, 'error');
        } else {
            error_log('[ZS Migration] Migration completed successfully');
            add_settings_error('zs_metabox_migration', 'migration_success', $message, 'success');
        }

        set_transient('zs_metabox_migration_results', $results, 300);

        wp_safe_redirect(admin_url('admin.php?page=zs_metabox_migration&zs_migration_load=1'));
        exit;
    }

    public function handleReset(): void
    {
        error_log('[ZS Migration] handleReset() called');
        
        if (!current_user_can('manage_options')) {
            error_log('[ZS Migration] User lacks manage_options permission');
            wp_die(__('You do not have permission to perform this action.', 'zero-sense'));
        }

        error_log('[ZS Migration] Checking nonce...');
        check_admin_referer('zs_metabox_reset');
        error_log('[ZS Migration] Nonce verified, resetting migration status...');

        $results = $this->migrator->resetMigrationStatus();
        $message = sprintf(
            __('Migration status reset. %1$d orders marked as pending for migration.', 'zero-sense'),
            $results['reset_count']
        );

        error_log('[ZS Migration] Reset results: ' . json_encode($results));
        
        add_settings_error('zs_metabox_migration', 'reset_success', $message, 'success');

        error_log('[ZS Migration] Redirecting back to referrer...');
        wp_safe_redirect(wp_get_referer());
        exit;
    }

    public function handleRollback(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'zero-sense'));
        }

        check_admin_referer('zs_metabox_rollback');

        add_settings_error('zs_metabox_migration', 'rollback_info', __('Rollback functionality not yet implemented.', 'info'), 'info');

        wp_safe_redirect(wp_get_referer());
        exit;
    }

    public function handlePreview(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'zero-sense'));
        }

        check_admin_referer('zs_metabox_preview');

        wp_safe_redirect(wp_get_referer());
        exit;
    }

    public function ajaxGetStatus(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'zero-sense')], 403);
        }

        $status = $this->migrator->getMigrationStatus();
        wp_send_json_success($status);
    }
}
