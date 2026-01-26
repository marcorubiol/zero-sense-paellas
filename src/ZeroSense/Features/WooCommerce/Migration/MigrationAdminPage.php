<?php
namespace ZeroSense\Features\WooCommerce\Migration;

use ZeroSense\Core\FeatureInterface;

/**
 * Migration Admin Page
 * 
 * Provides admin interface for MetaBox to ZeroSense migration
 * Last webhook test: 2026-01-26 12:36 - Test deploy fix
 */
class MigrationAdminPage implements FeatureInterface
{
    private MetaBoxMigrator $migrator;

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
        return __('WooCommerce', 'zero-sense');
    }

    public function isEnabled(): bool
    {
        return get_option('zs_metabox_migration_enabled', true);
    }

    public function getOptionKey(): string
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

        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_post_zs_metabox_migrate', [$this, 'handleMigration']);
        add_action('admin_post_zs_metabox_rollback', [$this, 'handleRollback']);
        add_action('admin_post_zs_metabox_preview', [$this, 'handlePreview']);
        add_action('wp_ajax_zs_metabox_status', [$this, 'ajaxGetStatus']);
    }

    public function addAdminMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('MetaBox Migration', 'zero-sense'),
            __('MetaBox Migration', 'zero-sense'),
            'manage_options',
            'zs_metabox_migration',
            [$this, 'renderAdminPage']
        );
    }

    public function renderAdminPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'zero-sense'));
        }

        $status = $this->migrator->getMigrationStatus();
        $sample_orders = $this->migrator->getSampleOrders(3);
        
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
                <!-- Migration Status -->
                <div class="zs-migration-status-card">
                    <h2><?php esc_html_e('Migration Status', 'zero-sense'); ?></h2>
                    <div class="zs-status-grid">
                        <div class="zs-status-item">
                            <span class="zs-status-label"><?php esc_html_e('Total Orders:', 'zero-sense'); ?></span>
                            <span class="zs-status-value"><?php echo esc_html($status['total_orders']); ?></span>
                        </div>
                        <div class="zs-status-item">
                            <span class="zs-status-label"><?php esc_html_e('Migrated:', 'zero-sense'); ?></span>
                            <span class="zs-status-value zs-success"><?php echo esc_html($status['migrated_orders']); ?></span>
                        </div>
                        <div class="zs-status-item">
                            <span class="zs-status-label"><?php esc_html_e('Pending:', 'zero-sense'); ?></span>
                            <span class="zs-status-value <?php echo $status['pending_orders'] > 0 ? 'zs-warning' : 'zs-success'; ?>">
                                <?php echo esc_html($status['pending_orders']); ?>
                            </span>
                        </div>
                    </div>
                    
                    <?php if ($status['migration_complete']): ?>
                        <div class="notice notice-success">
                            <p><?php esc_html_e('✅ Migration complete! All orders have been migrated.', 'zero-sense'); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($status['last_migration']): ?>
                        <p class="zs-last-migration">
                            <strong><?php esc_html_e('Last migration:', 'zero-sense'); ?></strong>
                            <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($status['last_migration']))); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Sample Orders Preview -->
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

                <!-- Action Buttons -->
                <div class="zs-migration-actions">
                    <?php if (!$status['migration_complete']): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="zs-migration-form">
                            <?php wp_nonce_field('zs_metabox_migrate', 'zs_metabox_nonce'); ?>
                            <input type="hidden" name="action" value="zs_metabox_migrate">
                            
                            <button type="submit" class="button button-primary zs-migrate-btn">
                                <?php esc_html_e('Start Migration', 'zero-sense'); ?>
                            </button>
                            
                            <span class="zs-migration-note">
                                <?php esc_html_e('This will migrate all pending orders in batches of 50.', 'zero-sense'); ?>
                            </span>
                        </form>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="zs-preview-form">
                        <?php wp_nonce_field('zs_metabox_preview', 'zs_metabox_nonce'); ?>
                        <input type="hidden" name="action" value="zs_metabox_preview">
                        
                        <button type="submit" class="button">
                            <?php esc_html_e('Refresh Preview', 'zero-sense'); ?>
                        </button>
                    </form>

                    <?php if ($status['migrated_orders'] > 0): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="zs-rollback-form">
                            <?php wp_nonce_field('zs_metabox_rollback', 'zs_metabox_nonce'); ?>
                            <input type="hidden" name="action" value="zs_metabox_rollback">
                            
                            <button type="submit" class="button zs-rollback-btn" onclick="return confirm('<?php esc_attr_e('Are you sure you want to rollback all migrations? This will remove all migrated ZeroSense fields and restore MetaBox fields only.', 'zero-sense'); ?>');">
                                <?php esc_html_e('Rollback All', 'zero-sense'); ?>
                            </button>
                            
                            <span class="zs-rollback-note">
                                <?php esc_html_e('⚠️ This will remove all migrated ZeroSense fields.', 'zero-sense'); ?>
                            </span>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <style>
                .zs-migration-dashboard {
                    margin-top: 20px;
                }
                .zs-migration-status-card,
                .zs-migration-preview-card,
                .zs-migration-actions {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                    padding: 20px;
                    margin-bottom: 20px;
                }
                .zs-status-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                    gap: 15px;
                    margin: 15px 0;
                }
                .zs-status-item {
                    text-align: center;
                    padding: 15px;
                    background: #f9f9f9;
                    border-radius: 4px;
                }
                .zs-status-label {
                    display: block;
                    font-size: 12px;
                    color: #666;
                    margin-bottom: 5px;
                }
                .zs-status-value {
                    display: block;
                    font-size: 24px;
                    font-weight: bold;
                }
                .zs-success { color: #46b450; }
                .zs-warning { color: #ffb900; }
                .zs-preview-table {
                    margin-top: 15px;
                }
                .zs-field-list {
                    margin: 0;
                    padding-left: 15px;
                    font-size: 12px;
                }
                .zs-field-list li {
                    margin-bottom: 3px;
                }
                .zs-migration-actions {
                    display: flex;
                    gap: 15px;
                    align-items: center;
                    flex-wrap: wrap;
                }
                .zs-migration-form,
                .zs-preview-form,
                .zs-rollback-form {
                    display: inline-block;
                }
                .zs-migrate-btn {
                    background: #46b450;
                    border-color: #46b450;
                }
                .zs-rollback-btn {
                    background: #d63638;
                    border-color: #d63638;
                    color: white;
                }
                .zs-migration-note,
                .zs-rollback-note {
                    font-size: 12px;
                    color: #666;
                    margin-left: 10px;
                }
                .zs-last-migration {
                    margin-top: 10px;
                    font-size: 12px;
                    color: #666;
                }
            </style>
        </div>
        <?php
    }

    public function handleMigration(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.', 'zero-sense'));
        }

        if (!isset($_POST['zs_metabox_nonce']) || !wp_verify_nonce($_POST['zs_metabox_nonce'], 'zs_metabox_migrate')) {
            wp_die(__('Security check failed.', 'zero-sense'));
        }

        $results = $this->migrator->migrateAll();
        
        // Build result message
        $message = sprintf(
            __('Migration completed. Success: %d, Errors: %d, Skipped: %d', 'zero-sense'),
            $results['success'],
            $results['errors'],
            $results['skipped']
        );

        if ($results['errors'] > 0) {
            add_settings_error('zs_metabox_migration', 'migration_error', $message, 'error');
        } else {
            add_settings_error('zs_metabox_migration', 'migration_success', $message, 'success');
        }

        // Store results for display
        set_transient('zs_metabox_migration_results', $results, 300);

        wp_safe_redirect(wp_get_referer());
        exit;
    }

    public function handleRollback(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.', 'zero-sense'));
        }

        if (!isset($_POST['zs_metabox_nonce']) || !wp_verify_nonce($_POST['zs_metabox_nonce'], 'zs_metabox_rollback')) {
            wp_die(__('Security check failed.', 'zero-sense'));
        }

        // This would need to be implemented for full rollback
        // For now, just show a message
        add_settings_error('zs_metabox_migration', 'rollback_info', __('Rollback functionality not yet implemented.', 'info'), 'info');

        wp_safe_redirect(wp_get_referer());
        exit;
    }

    public function handlePreview(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.', 'zero-sense'));
        }

        if (!isset($_POST['zs_metabox_nonce']) || !wp_verify_nonce($_POST['zs_metabox_nonce'], 'zs_metabox_preview')) {
            wp_die(__('Security check failed.', 'zero-sense'));
        }

        wp_safe_redirect(wp_get_referer());
        exit;
    }

    public function ajaxGetStatus(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.', 'zero-sense'));
        }

        if (!check_ajax_referer('zs_metabox_nonce', 'nonce')) {
            wp_die(__('Security check failed.', 'zero-sense'));
        }

        $status = $this->migrator->getMigrationStatus();
        wp_send_json_success($status);
    }

    public function getDependencies(): array
    {
        return ['woocommerce'];
    }
}
